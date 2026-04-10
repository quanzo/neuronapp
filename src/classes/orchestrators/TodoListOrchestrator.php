<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\orchestrators;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\events\OrchestratorEventDto;
use app\modules\neuron\classes\dto\events\OrchestratorErrorEventDto;
use app\modules\neuron\classes\dto\events\OrchestratorResumeHistoryMissingEventDto;
use app\modules\neuron\classes\dto\orchestrator\OrchestratorResultDto;
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\helpers\TodoCompletedStatusHelper;
use app\modules\neuron\helpers\TodoListResumeHelper;
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
 * - оркестратор намеренно не опирается на todo_goto (переходы между пунктами
 *   внутри одного запуска {@see \app\modules\neuron\classes\todo\TodoList::execute()}
 *   обрабатываются там же через {@see \app\modules\neuron\classes\dto\run\RunStateDto});
 * - источник истины для статуса завершения внешнего цикла — ключ `completed` в
 *   {@see \app\modules\neuron\classes\storage\IntermediateStorage}.
 *
 * Возобновление по чекпоинту RunStateDto (межпроцессный resume):
 * - перед каждым вызовом {@see \app\modules\neuron\classes\todo\TodoList::execute()}
 *   вычисляется `startFromTodoIndex` так же по смыслу, как у команды `todolist --resume`:
 *   читается {@see \app\modules\neuron\classes\config\ConfigurationAgent::getExistRunStateDto()},
 *   при совпадении {@see \app\modules\neuron\classes\dto\run\RunStateDto::getTodolistName()}
 *   с именем текущего списка (`TodoList::getName()`), при `finished === false` и совпадении
 *   ключа сессии с {@see \app\modules\neuron\classes\config\ConfigurationApp::getSessionKey()}
 *   выполняется откат истории чата
 *   до {@see \app\modules\neuron\classes\dto\run\RunStateDto::getHistoryMessageCount()}
 *   (если задано) и передаётся индекс `last_completed_todo_index + 1`;
 * - если чекпоинта нет, run помечен завершённым, имя списка или сессия не совпадают —
 *   выполнение списка начинается с пункта 0;
 * - после успешного прохода списка `TodoList` удаляет чекпоинт, поэтому следующий этап
 *   (`init` → `step` → `finish`) обычно стартует «с нуля» без конфликта имён;
 * - при `restartOnFail` новая попытка цикла снова применяет эти правила к каждому списку
 *   (например, незавершённый `step` может быть продолжен с сохранённого индекса).
 *
 * Пример (прервано на втором пункте списка `step`):
 * - в `.store` остался `RunStateDto` с `todolist_name = step`, `last_completed_todo_index = 0`;
 * - при следующем запуске оркестратора после `init` для списка `step` будет вызван
 *   `execute(..., startFromTodoIndex = 1, ...)`, история — усечена по `history_message_count`.
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
                    $completedRaw = $this->getCompleted();
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
                $restartErrDto = $this->buildOrchestratorErrorEventDto($restartCount);
                $restartErrDto->setReason('restart_after_error');
                $restartErrDto->setErrorClass($e::class);
                $restartErrDto->setErrorMessage($e->getMessage());
                EventBus::trigger(
                    EventNameEnum::ORCHESTRATOR_RESTARTED->value,
                    $this,
                    $restartErrDto
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
        $event = $this->buildOrchestratorErrorEventDto($result?->getRestartCount() ?? 0);
        $event->setIterations($result?->getIterations() ?? 0);
        $event->setCompletedRaw($result?->getCompletedRaw());
        $event->setCompletedNormalized($result?->getCompletedNormalized());
        $event->setReason($reason instanceof \Throwable ? 'error' : (string) $reason);

        if ($reason instanceof \Throwable) {
            $event->setErrorClass($reason::class);
            $event->setErrorMessage($reason->getMessage());
        }

        EventBus::trigger(
            EventNameEnum::ORCHESTRATOR_FAILED->value,
            $this,
            $event
        );
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
        return TodoCompletedStatusHelper::normalize($raw);
    }

    /**
     * Принудительно записывает completed в промежуточное хранилище.
     *
     * @param int $value Нормализованное значение completed (обычно 0 или 1).
     * @param string $description Человекочитаемое описание причины записи.
     */
    protected function setCompleted(int $value, string $description): void
    {
        $this->configApp->getVarStorage()->save($this->configApp->getSessionKey(), 'completed', $value, $description);
    }

    /**
     * Считывает сырое значение completed из intermediate storage.
     *
     * @return mixed Исходное значение ключа `completed` либо `null`,
     * если ключ отсутствует.
     */
    protected function getCompleted(): mixed
    {
        $storage = $this->configApp->getVarStorage();
        $payload = $storage->load($this->configApp->getSessionKey(), 'completed');

        return $payload['data'] ?? 0;
    }

    /**
     * Синхронно запускает выполнение TodoList через amp/revolt.
     *
     * Реализация:
     * - определяет {@see \app\modules\neuron\classes\todo\TodoList::execute()} четвёртый аргумент
     *   (`startFromTodoIndex`) и при необходимости откатывает историю чата по чекпоинту
     *   {@see resolveStartFromTodoIndexForTodoList()} — см. описание класса;
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

        $startFromTodoIndex = $this->resolveStartFromTodoIndexForTodoList($todoList);

        EventLoop::queue(static function () use ($todoList, $sessionParams, $startFromTodoIndex, &$error): void {
            try {
                $todoList->execute(
                    MessageRole::USER,
                    [],
                    null,
                    $startFromTodoIndex,
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
     * Вычисляет индекс первого todo и при необходимости откатывает историю чата для resume.
     *
     * Логика согласована с {@see \app\modules\neuron\classes\command\TodolistCommand}
     * (ветка `--resume`): чекпоинт один на сессию (`RunStateDto::DEF_AGENT_NAME`), поле
     * `todolist_name` должно совпадать с исполняемым списком, иначе resume не применяется
     * (типичный случай — после успешного `init` чекпоинт удалён, перед `step` файла нет).
     *
     * @param TodoList $todoList Список, который будет передан в `execute()`.
     *
     * @return int Индекс первого пункта для `TodoList::execute()` (0 = с начала).
     *
     * Если resume выполняется без `history_message_count` в чекпоинте, публикуется
     * {@see EventNameEnum::ORCHESTRATOR_RESUME_HISTORY_MISSING} с
     * {@see OrchestratorResumeHistoryMissingEventDto}; логирование — у {@see \app\modules\neuron\classes\events\subscribers\OrchestratorLoggingSubscriber}.
     */
    protected function resolveStartFromTodoIndexForTodoList(TodoList $todoList): int
    {
        $agentCfg = $todoList->getConfigurationAgent();
        $plan = TodoListResumeHelper::buildPlan($agentCfg, $todoList->getName(), $this->configApp->getSessionKey());

        if (!$plan->isResumeAvailable()) {
            return 0;
        }

        if (!TodoListResumeHelper::applyHistoryRollback($agentCfg, $plan)) {
            EventBus::trigger(
                EventNameEnum::ORCHESTRATOR_RESUME_HISTORY_MISSING->value,
                $this,
                (new OrchestratorResumeHistoryMissingEventDto())
                    ->setSessionKey($this->configApp->getSessionKey())
                    ->setRunId($this->configApp->getSessionKey())
                    ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
                    ->setAgent($agentCfg)
                    ->setTodolistName($todoList->getName())
                    ->setLastCompletedTodoIndex($plan->getLastCompletedTodoIndex())
                    ->setStartFromTodoIndex($plan->getStartFromTodoIndex())
            );
        }

        return $plan->getStartFromTodoIndex();
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

    /**
     * Создает DTO ошибки оркестраторного события.
     *
     * @param int $restartCount Текущий номер попытки (0 для первой).
     * @return OrchestratorErrorEventDto
     */
    private function buildOrchestratorErrorEventDto(int $restartCount): OrchestratorErrorEventDto
    {
        $dto = new OrchestratorErrorEventDto();
        $dto->setSessionKey($this->configApp->getSessionKey());
        $dto->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $dto->setAgent(null);
        $dto->setRestartCount($restartCount);
        return $dto;
    }
}
