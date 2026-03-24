<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\orchestrators;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\events\OrchestratorEventDto;
use app\modules\neuron\classes\dto\orchestrator\OrchestratorResultDto;
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\classes\todo\TodoList;
use NeuronAI\Chat\Enums\MessageRole;
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
    public function __construct(
        private readonly ConfigurationApp $configApp,
    ) {
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
        int               $maxIterations = 100,
        bool              $restartOnFail = false,
        int               $maxRestarts   = 0,
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
                EventBus::trigger(
                    EventNameEnum::ORCHESTRATOR_CYCLE_STARTED->value,
                    $this,
                    $this->buildOrchestratorEventDto($restartCount)
                );

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

                    EventBus::trigger(
                        EventNameEnum::ORCHESTRATOR_STEP_COMPLETED->value,
                        $this,
                        $this->buildOrchestratorEventDto($restartCount)
                            ->setIterations($i)
                            ->setCompletedRaw($completedRaw)
                            ->setCompletedNormalized($completedNormalized)
                    );

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
                    throw $e;
                }

                ++$restartCount;
                EventBus::trigger(
                    EventNameEnum::ORCHESTRATOR_RESTARTED->value,
                    $this,
                    $this->buildOrchestratorEventDto($restartCount)
                        ->setReason('restart_after_error')
                        ->setSuccess(false)
                        ->setErrorClass($e::class)
                        ->setErrorMessage($e->getMessage())
                );
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
        EventBus::trigger(
            EventNameEnum::ORCHESTRATOR_COMPLETED->value,
            $this,
            $this->buildOrchestratorEventDto($result->getRestartCount())
                ->setIterations($result->getIterations())
                ->setCompletedRaw($result->getCompletedRaw())
                ->setCompletedNormalized($result->getCompletedNormalized())
                ->setReason($result->getReason())
                ->setSuccess($result->isSuccess())
        );
    }

    /**
     * Callback ошибочного завершения цикла или завершения по лимиту.
     *
     * @param \Throwable|string $reason Исключение или строковый код причины.
     */
    protected function onFail(\Throwable|string $reason, ?OrchestratorResultDto $result = null): void
    {
        $event = $this->buildOrchestratorEventDto($result?->getRestartCount() ?? 0)
            ->setIterations($result?->getIterations() ?? 0)
            ->setCompletedRaw($result?->getCompletedRaw())
            ->setCompletedNormalized($result?->getCompletedNormalized())
            ->setReason($reason instanceof \Throwable ? 'error' : (string) $reason)
            ->setSuccess(false);

        if ($reason instanceof \Throwable) {
            $event
                ->setErrorClass($reason::class)
                ->setErrorMessage($reason->getMessage());
        }

        EventBus::trigger(
            EventNameEnum::ORCHESTRATOR_FAILED->value,
            $this,
            $event
        );
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
     * Создает DTO оркестраторного события.
     */
    private function buildOrchestratorEventDto(int $restartCount): OrchestratorEventDto
    {
        return (new OrchestratorEventDto())
            ->setSessionKey($this->configApp->getSessionKey())
            ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
            ->setAgent(null)
            ->setRestartCount($restartCount);
    }
}
