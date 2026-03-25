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
 * Назначение:
 * - запускать управляемый "внешний цикл" поверх трех списков задач:
 *   `init`, `step`, `finish`;
 * - читать флаг `completed` из промежуточного хранилища и принимать
 *   решение о завершении или продолжении итераций;
 * - публиковать жизненный цикл оркестратора в EventBus.
 *
 * Базовый сценарий работы:
 * 1. Публикуется событие `orchestrator.cycle_started`.
 * 2. В storage принудительно устанавливается `completed = 0`.
 * 3. Выполняется `init` список.
 * 4. Выполняется цикл `step` до:
 *    - `completed == 1`, либо
 *    - достижения лимита `maxIterations`.
 * 5. Выполняется `finish` список.
 * 6. Публикуется событие успеха (`orchestrator.completed`) или неуспеха
 *    (`orchestrator.failed`), в зависимости от результата.
 *
 * Поведение при ошибках:
 * - любая ошибка внутри цикла приводит к `onFail(...)`;
 * - при включенном `restartOnFail` цикл может быть перезапущен ограниченное
 *   число раз (`maxRestarts`);
 * - при каждом рестарте публикуется `orchestrator.restarted`.
 *
 * Важно:
 * - оркестратор намеренно не опирается на todo_goto;
 * - источник истины для статуса завершения — ключ `completed` в
 *   {@see \app\modules\neuron\classes\storage\VarStorage}.
 */
class TodoListOrchestrator
{
    /**
     * @param ConfigurationApp $configApp Глобальная конфигурация приложения.
     *
     * Через объект конфигурации оркестратор получает:
     * - sessionKey;
     * - доступ к VarStorage;
     * - фабрики/поиск зависимостей, необходимых во время исполнения списков.
     */
    public function __construct(
        private readonly ConfigurationApp $configApp,
    ) {
    }

    /**
     * Запускает внешний цикл оркестрации.
     *
     * Контракт метода:
     * - всегда выполняет `init` перед основным циклом;
     * - выполняет `step` до достижения условия завершения либо лимита;
     * - всегда выполняет `finish` перед возвратом результата, если не произошло
     *   фатального исключения без разрешенного рестарта;
     * - возвращает DTO с агрегированной диагностикой исполнения.
     *
     * @param TodoList $initTodoList   Список инициализации (подготовка контекста).
     * @param TodoList $stepTodoList   Список шага (итеративная часть цикла).
     * @param TodoList $finishTodoList Список завершения (cleanup/finalization).
     * @param int $maxIterations       Максимальное количество итераций шага.
     * @param bool $restartOnFail      Разрешает перезапуск цикла после ошибки.
     * @param int $maxRestarts         Максимум допустимых перезапусков.
     * @param SessionParamsDto|null $sessionParams Параметры подстановки в todo.
     *
     * @return OrchestratorResultDto
     *
     * @throws \Throwable Пробрасывает исходное исключение, если рестарт не
     * разрешен или исчерпан лимит перезапусков.
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

        // Базовый результат с ключом сессии и нулевыми счетчиками.
        $result = (new OrchestratorResultDto())
            ->setSessionKey($this->configApp->getSessionKey())
            ->setIterations(0)
            ->setRestartCount(0);

        // Защита от некорректных входных параметров.
        $maxIterations = max(1, $maxIterations);
        $maxRestarts   = max(0, $maxRestarts);
        $restartCount  = 0;

        while (true) {
            try {
                // Старт новой попытки внешнего цикла (первой или после рестарта).
                EventBus::trigger(
                    EventNameEnum::ORCHESTRATOR_CYCLE_STARTED->value,
                    $this,
                    $this->buildOrchestratorEventDto($restartCount)
                );

                // Явно сбрасываем completed перед init, чтобы step-цикл
                // начинался из предсказуемого состояния.
                $this->setCompleted(0, 'orchestrator-start');
                $this->executeTodoList($initTodoList, $sessionParams);

                $completedByLimit    = true;
                $completedRaw        = null;
                $completedNormalized = null;
                $iterations          = 0;

                for ($i = 1; $i <= $maxIterations; $i++) {
                    // Один шаг внешнего цикла.
                    $this->executeTodoList($stepTodoList, $sessionParams);
                    $iterations = $i;

                    // Читаем completed, который должен выставляться шагами.
                    $completedRaw = $this->readCompletedRaw();
                    $completedNormalized = $this->normalizeCompleted($completedRaw);

                    // Публикуем состояние после каждой итерации шага.
                    EventBus::trigger(
                        EventNameEnum::ORCHESTRATOR_STEP_COMPLETED->value,
                        $this,
                        $this->buildOrchestratorEventDto($restartCount)
                            ->setIterations($i)
                            ->setCompletedRaw($completedRaw)
                            ->setCompletedNormalized($completedNormalized)
                    );

                    if ($completedNormalized === 1) {
                        // Условие завершения достигнуто.
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
                    // Лимит итераций исчерпан: finish выполняем все равно.
                    $this->executeTodoList($finishTodoList, $sessionParams);
                    $result
                        ->setSuccess(false)
                        ->setReason('max_iterations');
                    $this->onFail('max_iterations', $result);
                } else {
                    // Нормальное завершение: финализируем и публикуем complete.
                    $this->executeTodoList($finishTodoList, $sessionParams);
                    $result
                        ->setSuccess(true)
                        ->setReason('completed');
                    $this->onComplete($result);
                }

                return $result;
            } catch (\Throwable $e) {
                // Любая ошибка переводит результат в failed-состояние.
                $result
                    ->setSuccess(false)
                    ->setReason('error')
                    ->setRestartCount($restartCount);
                $this->onFail($e, $result);

                if (!$restartOnFail || $restartCount >= $maxRestarts) {
                    // Рестарт запрещен или исчерпан — пробрасываем исходную ошибку.
                    throw $e;
                }

                // Переходим к новой попытке цикла.
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
     * Выделен в отдельный protected-метод, чтобы наследники могли
     * переопределять post-success поведение (метрики, интеграции, уведомления),
     * не меняя основной алгоритм в {@see run()}.
     *
     * @param OrchestratorResultDto $result Итог исполнения цикла.
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
     * Выделен в отдельный protected-метод для возможности расширения
     * ошибочных сценариев наследниками.
     *
     * @param \Throwable|string $reason Исключение или строковый код причины.
     * @param OrchestratorResultDto|null $result Частично заполненный результат.
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
     *
     * @return mixed Исходное значение ключа `completed` либо `null`,
     * если ключ отсутствует.
     */
    protected function readCompletedRaw(): mixed
    {
        $storage = $this->configApp->getVarStorage();
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
     *
     * @param mixed $raw Сырое значение из storage.
     *
     * @return int|null 1/0 для валидных значений, null для неизвестного формата.
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
     *
     * @param int $value Нормализованное значение completed (обычно 0 или 1).
     * @param string $description Человекочитаемое описание причины записи.
     */
    protected function setCompleted(int $value, string $description): void
    {
        $this->configApp
            ->getVarStorage()
            ->save($this->configApp->getSessionKey(), 'completed', $value, $description);
    }

    /**
     * Синхронно запускает выполнение TodoList через amp/revolt.
     *
     * Реализация:
     * - помещает задачу в очередь EventLoop;
     * - внутри очереди вызывает async-исполнение TodoList и ожидает завершение;
     * - после EventLoop::run() пробрасывает ошибку, если она была поймана.
     *
     * @param TodoList $todoList Список заданий для исполнения.
     * @param SessionParamsDto|null $sessionParams Параметры подстановки в todo.
     *
     * @return void
     *
     * @throws \Throwable Ошибка, произошедшая во время выполнения TodoList.
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
     *
     * @param int $restartCount Текущий номер попытки (0 для первой).
     * @return OrchestratorEventDto
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
