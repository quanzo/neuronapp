<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\orchestrators;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\orchestrator\OrchestratorResultDto;
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\classes\todo\TodoList;
use NeuronAI\Chat\Enums\MessageRole;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Оркестратор внешнего цикла выполнения TodoList-сценариев.
 *
 * Класс реализует детерминированный цикл:
 * 1) выполняет init-list,
 * 2) принудительно ставит completed=0 в intermediate storage,
 * 3) выполняет step-list до completed=1 или до лимита итераций,
 * 4) выполняет finish-list при успехе либо при исчерпании лимита.
 *
 * Дополнительно поддерживаются:
 * - callbacks жизненного цикла: {@see onComplete()} и {@see onFail()};
 * - перезапуск (restart) цикла после ошибок со счетчиком попыток;
 * - настраиваемая детализация логов и возможность отключить логирование.
 *
 * Важно: оркестратор не полагается на todo_goto, а читает флаг completed
 * напрямую из {@see \app\modules\neuron\classes\storage\IntermediateStorage}.
 */
class TodoListOrchestrator
{
    public const LOG_OFF = 'off';
    public const LOG_MINIMAL = 'minimal';
    public const LOG_NORMAL = 'normal';
    public const LOG_DEBUG = 'debug';

    public function __construct(
        private readonly ConfigurationApp $configApp,
        private readonly ?LoggerInterface $logger = null,
        private bool $enableLogging = true,
        private string $logLevel = self::LOG_NORMAL,
    ) {
    }

    /**
     * Включает/выключает логирование оркестратора.
     */
    public function setEnableLogging(bool $enableLogging): self
    {
        $this->enableLogging = $enableLogging;
        return $this;
    }

    /**
     * Устанавливает уровень детализации логирования.
     *
     * Допустимые уровни: off|minimal|normal|debug.
     */
    public function setLogLevel(string $logLevel): self
    {
        $this->logLevel = $this->normalizeLogLevel($logLevel);
        return $this;
    }

    /**
     * Запускает внешний цикл оркестрации.
     *
     * @param TodoList $initTodoList   Список инициализации.
     * @param TodoList $stepTodoList   Шаг цикла.
     * @param TodoList $finishTodoList Завершающий список.
     * @param int $maxIterations       Максимум итераций step.
     * @param bool $restartOnFail      Разрешить перезапуск при исключении в цикле.
     * @param int $maxRestarts         Максимум перезапусков цикла.
     * @param SessionParamsDto|null $sessionParams Параметры подстановки в todo.
     */
    public function run(
        TodoList $initTodoList,
        TodoList $stepTodoList,
        TodoList $finishTodoList,
        int $maxIterations = 100,
        bool $restartOnFail = false,
        int $maxRestarts = 0,
        ?SessionParamsDto $sessionParams = null
    ): OrchestratorResultDto {

        $result = (new OrchestratorResultDto())
            ->setSessionKey($this->configApp->getSessionKey())
            ->setIterations(0)
            ->setRestartCount(0);

        $maxIterations = max(1, $maxIterations);
        $maxRestarts   = max(0, $maxRestarts);
        $restartCount  = 0;

        while (true) {
            try {
                $this->logInfo('orchestrator.start_cycle', [
                    'restart_count'  => $restartCount,
                    'max_restarts'   => $maxRestarts,
                    'max_iterations' => $maxIterations,
                ], self::LOG_MINIMAL);

                $this->setCompleted(0, 'orchestrator-start');
                $this->executeTodoList($initTodoList, $sessionParams);

                $completedByLimit    = true;
                $completedRaw        = null;
                $completedNormalized = null;
                $iterations          = 0;

                for ($i = 1; $i <= $maxIterations; $i++) {
                    $this->executeTodoList($stepTodoList, $sessionParams);
                    $iterations = $i;

                    $completedRaw = $this->readCompletedRaw();
                    $completedNormalized = $this->normalizeCompleted($completedRaw);

                    $this->logInfo('orchestrator.step_state', [
                        'iteration'            => $i,
                        'completed_raw'        => $completedRaw,
                        'completed_normalized' => $completedNormalized,
                    ], self::LOG_NORMAL);

                    if ($completedNormalized === 1) {
                        $completedByLimit = false;
                        break;
                    }
                }

                $result
                    ->setIterations($iterations)
                    ->setRestartCount($restartCount)
                    ->setCompletedRaw($completedRaw)
                    ->setCompletedNormalized($completedNormalized);

                if ($completedByLimit) {
                    $this->logWarning('orchestrator.max_iterations_reached', [
                        'iterations'     => $iterations,
                        'max_iterations' => $maxIterations,
                    ], self::LOG_MINIMAL);

                    $this->executeTodoList($finishTodoList, $sessionParams);
                    $result
                        ->setSuccess(false)
                        ->setReason('max_iterations');
                    $this->onFail('max_iterations', $result);
                } else {
                    $this->executeTodoList($finishTodoList, $sessionParams);
                    $result
                        ->setSuccess(true)
                        ->setReason('completed');
                    $this->onComplete($result);
                }

                return $result;
            } catch (\Throwable $e) {
                $result
                    ->setSuccess(false)
                    ->setReason('error')
                    ->setRestartCount($restartCount);
                $this->onFail($e, $result);

                if (!$restartOnFail || $restartCount >= $maxRestarts) {
                    $this->logError('orchestrator.stopped_by_error', [
                        'restart_on_fail' => $restartOnFail,
                        'restart_count'   => $restartCount,
                        'max_restarts'    => $maxRestarts,
                        'error'           => $e->getMessage(),
                    ], self::LOG_MINIMAL);
                    throw $e;
                }

                ++$restartCount;
                $this->logWarning('orchestrator.restart_after_error', [
                    'restart_count' => $restartCount,
                    'max_restarts'  => $maxRestarts,
                    'error'         => $e->getMessage(),
                ], self::LOG_MINIMAL);
            }
        }
    }

    /**
     * Callback успешного завершения цикла.
     *
     * Метод сделан отдельным для расширения наследниками (например, метрики/уведомления).
     */
    protected function onComplete(OrchestratorResultDto $result): void
    {
        $this->logInfo('orchestrator.on_complete', $result->toArray(), self::LOG_MINIMAL);
    }

    /**
     * Callback ошибочного завершения цикла или завершения по лимиту.
     *
     * @param \Throwable|string $reason Исключение или строковый код причины.
     */
    protected function onFail(\Throwable|string $reason, ?OrchestratorResultDto $result = null): void
    {
        $payload = $result?->toArray() ?? [];
        if ($reason instanceof \Throwable) {
            $payload['error'] = $reason->getMessage();
        } else {
            $payload['error'] = $reason;
        }
        $this->logWarning('orchestrator.on_fail', $payload, self::LOG_MINIMAL);
    }

    /**
     * Считывает сырое значение completed из intermediate storage.
     */
    protected function readCompletedRaw(): mixed
    {
        $storage = $this->configApp->getIntermediateStorage();
        $payload = $storage->load($this->configApp->getSessionKey(), 'completed');

        return $payload['data'] ?? null;
    }

    /**
     * Нормализует completed к значениям:
     * - 1: выполнено
     * - 0: не выполнено
     * - null: неизвестно/невалидно
     *
     * Для совместимости поддерживаются строки:
     * "исполнено", "не исполнено", "done", "not_done", "true", "false", "1", "0".
     */
    protected function normalizeCompleted(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? 1 : 0;
        }
        if (is_bool($raw)) {
            return $raw ? 1 : 0;
        }
        if (is_string($raw)) {
            $v = strtolower(trim($raw));
            if (in_array($v, ['1', 'true', 'done', 'исполнено'], true)) {
                return 1;
            }
            if (in_array($v, ['0', 'false', 'not_done', 'не исполнено', 'неисполнено'], true)) {
                return 0;
            }
        }

        return null;
    }

    /**
     * Принудительно записывает completed в промежуточное хранилище.
     */
    protected function setCompleted(int $value, string $description): void
    {
        $this->configApp
            ->getIntermediateStorage()
            ->save($this->configApp->getSessionKey(), 'completed', $value, $description);
    }

    /**
     * Синхронно запускает выполнение TodoList через amp/revolt.
     */
    private function executeTodoList(TodoList $todoList, ?SessionParamsDto $sessionParams): void
    {
        $error = null;

        EventLoop::queue(static function () use ($todoList, $sessionParams, &$error): void {
            try {
                $todoList->execute(
                    MessageRole::USER,
                    [],
                    null,
                    0,
                    $sessionParams
                )->await();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        EventLoop::run();

        if ($error instanceof \Throwable) {
            throw $error;
        }
    }

    /**
     * Приводит уровень логирования к поддерживаемому набору.
     */
    private function normalizeLogLevel(string $level): string
    {
        $v = strtolower(trim($level));
        return match ($v) {
            self::LOG_OFF, self::LOG_MINIMAL, self::LOG_NORMAL, self::LOG_DEBUG => $v,
            default => self::LOG_NORMAL,
        };
    }

    private function shouldLog(string $requiredLevel): bool
    {
        if (!$this->enableLogging || $this->logger === null || $this->logLevel === self::LOG_OFF) {
            return false;
        }

        $order = [
            self::LOG_MINIMAL => 1,
            self::LOG_NORMAL => 2,
            self::LOG_DEBUG => 3,
        ];

        $current = $order[$this->logLevel] ?? 2;
        $required = $order[$requiredLevel] ?? 2;

        return $current >= $required;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logInfo(string $message, array $context, string $requiredLevel): void
    {
        if ($this->shouldLog($requiredLevel)) {
            $this->logger?->info($message, $context);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logWarning(string $message, array $context, string $requiredLevel): void
    {
        if ($this->shouldLog($requiredLevel)) {
            $this->logger?->warning($message, $context);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logError(string $message, array $context, string $requiredLevel): void
    {
        if ($this->shouldLog($requiredLevel)) {
            $this->logger?->error($message, $context);
        }
    }
}
