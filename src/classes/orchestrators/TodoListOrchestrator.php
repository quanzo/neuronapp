<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\orchestrators;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\events\OrchestratorEventDto;
use app\modules\neuron\classes\dto\events\OrchestratorResumeHistoryMissingEventDto;
use app\modules\neuron\classes\dto\orchestrator\OrchestratorResultDto;
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\classes\neuron\summarize\SummarizeService;
use app\modules\neuron\helpers\ChatHistoryEditHelper;
use app\modules\neuron\helpers\ChatHistoryRollbackHelper;
use app\modules\neuron\helpers\ChatHistoryTruncateHelper;
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
     * Ключ `config.jsonc`: включение/выключение суммаризации step-шага.
     *
     * Тип: bool.
     * Дефолт: false.
     *
     * Если выключено — оркестратор не считает snapshots и не вызывает {@see SummarizeService}.
     */
    private const CFG_STEP_HISTORY_SUMMARY_ENABLED = 'orchestrator.step_history_summarize.enabled';

    /**
     * Ключ `config.jsonc`: имя skill для суммаризации transcript.
     *
     * Тип: string.
     * Пример: "summarize/step_history".
     *
     * Используется только когда {@see CFG_STEP_HISTORY_SUMMARY_USE_SKILL} = true.
     * Разрешение skill выполняется через {@see \app\modules\neuron\classes\config\ConfigurationApp::getSkill()}.
     */
    private const CFG_STEP_HISTORY_SUMMARY_SKILL = 'orchestrator.step_history_summarize.skill';

    /**
     * Ключ `config.jsonc`: debug-логирование суммаризации.
     *
     * Тип: bool.
     * Дефолт: false.
     *
     * Если true — {@see SummarizeService} пишет события skip/apply с метриками
     * (delta, keptCount, transcriptChars, summaryChars, mode, role и т.п.) в PSR-3 логгер.
     */
    private const CFG_STEP_HISTORY_SUMMARY_DEBUG = 'orchestrator.step_history_summarize.debug';

    /**
     * Ключ `config.jsonc`: режим применения summary к истории.
     *
     * Тип: string.
     * Дефолт: "replace_range".
     * Допустимые значения:
     * - "replace_range": заменить сообщения текущего step-шага одним summary-сообщением;
     * - "append_summary": не удалять сообщения шага, а добавить summary отдельным сообщением после шага.
     */
    private const CFG_STEP_HISTORY_SUMMARY_MODE = 'orchestrator.step_history_summarize.mode';

    /**
     * Ключ `config.jsonc`: роль summary-сообщения.
     *
     * Тип: string.
     * Дефолт: "assistant".
     * Допустимые значения:
     * - "assistant"
     * - "system"
     *
     * Важно: роль влияет на то, как LLM будет воспринимать summary в дальнейших шагах
     * (как ответ ассистента или как системный контекст).
     */
    private const CFG_STEP_HISTORY_SUMMARY_ROLE = 'orchestrator.step_history_summarize.role';

    /**
     * Ключ `config.jsonc`: использовать ли Skill (LLM-вызов) для получения summary.
     *
     * Тип: bool.
     * Дефолт: false.
     *
     * Если false — summary = `transcript` (после фильтрации/дедупликации), без вызова LLM.
     * Если true  — summary вычисляется через skill {@see CFG_STEP_HISTORY_SUMMARY_SKILL}.
     */
    private const CFG_STEP_HISTORY_SUMMARY_USE_SKILL = 'orchestrator.step_history_summarize.use_skill';

    /**
     * Ключ `config.jsonc`: минимальная длина transcript для запуска суммаризации шага.
     *
     * Тип: int.
     * Дефолт: 50.
     *
     * Проверка выполняется после фильтрации и построения transcript.
     * Если transcript короче — шаг пропускается (summary не применяется к истории).
     */
    private const CFG_STEP_HISTORY_SUMMARY_MIN_TRANSCRIPT_CHARS = 'orchestrator.step_history_summarize.min_transcript_chars';

    /**
     * Ключ `config.jsonc`: фильтровать ли tool-call/tool-result сообщения из дельты шага.
     *
     * Тип: bool.
     * Дефолт: true.
     *
     * Обычно полезно, т.к. результаты инструментов могут быть большими и/или дублирующимися.
     */
    private const CFG_STEP_HISTORY_SUMMARY_FILTER_TOOL_MESSAGES = 'orchestrator.step_history_summarize.filter.tool_messages';

    /**
     * Ключ `config.jsonc`: фильтровать ли "history inspection tools" (`chat_history.*`).
     *
     * Тип: bool.
     * Дефолт: true.
     *
     * Это подмножество tool-сообщений; их ответы часто содержат большие дампы истории и резко раздувают transcript.
     */
    private const CFG_STEP_HISTORY_SUMMARY_FILTER_HISTORY_TOOLS = 'orchestrator.step_history_summarize.filter.history_tools';

    /**
     * Ключ `config.jsonc`: минимальная длина отдельного сообщения, чтобы оно попало в transcript.
     *
     * Тип: int.
     * Дефолт: 3.
     *
     * Нужен, чтобы выкидывать низкосигнальные реплики вроде ".", "ok", "да" и т.п.
     */
    private const CFG_STEP_HISTORY_SUMMARY_FILTER_MIN_MESSAGE_CHARS = 'orchestrator.step_history_summarize.filter.min_message_chars';

    /**
     * Ключ `config.jsonc`: удалять ли подряд повторяющиеся сообщения в дельте шага.
     *
     * Тип: bool.
     * Дефолт: true.
     *
     * Важно: дедупликация здесь только "consecutive" (подряд), чтобы не потерять смысл диалога.
     * Глобальная дедупликация контента внутри transcript дополнительно выполняется внутри {@see SummarizeService}.
     */
    private const CFG_STEP_HISTORY_SUMMARY_FILTER_DEDUP_CONSECUTIVE = 'orchestrator.step_history_summarize.filter.dedup_consecutive';

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

                $summarizeService = $this->buildSummarizeServiceForStep();

                for ($i = 1; $i <= $maxIterations; $i++) {
                    // Один шаг внешнего цикла.
                    $stepHistoryCountBefore = null;
                    if ($summarizeService !== null) {
                        $history = $stepTodoList->getConfigurationAgent()->getChatHistory();
                        $stepHistoryCountBefore = ChatHistoryRollbackHelper::getSnapshotCount($history);
                    }

                    $this->executeTodoList($stepTodoList, $sessionParams);
                    $iterations = $i;

                    if ($summarizeService !== null && $stepHistoryCountBefore !== null) {
                        $history               = $stepTodoList->getConfigurationAgent()->getChatHistory();
                        $stepHistoryCountAfter = ChatHistoryRollbackHelper::getSnapshotCount($history);
                        $stepMessagesCopy      = ChatHistoryEditHelper::copyMessagesBySnapshotRange(
                            $history,
                            $stepHistoryCountBefore,
                            $stepHistoryCountAfter
                        );
                        $summarizeService->summarizeAndApply(
                            agentCfg    : $stepTodoList->getConfigurationAgent(),
                            history     : $history,
                            countBefore : $stepHistoryCountBefore,
                            countAfter  : $stepHistoryCountAfter,
                            stepMessages: $stepMessagesCopy,
                            contextName : $stepTodoList->getName()
                        );
                    }

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
        $agentCfg    = $todoList->getConfigurationAgent();
        $runStateDto = $agentCfg->getExistRunStateDto();
        if ($runStateDto === null) {
            return 0;
        }
        if ($runStateDto->isFinished()) {
            return 0;
        }
        if ($runStateDto->getTodolistName() !== $todoList->getName()) {
            return 0;
        }
        $checkpointSessionKey = $runStateDto->getSessionKey();
        $appSessionKey      = $this->configApp->getSessionKey();
        if ($checkpointSessionKey !== '' && $checkpointSessionKey !== $appSessionKey) {
            return 0;
        }

        $startFromTodoIndex = max(0, $runStateDto->getLastCompletedTodoIndex() + 1);

        $historyMessageCount = $runStateDto->getHistoryMessageCount();
        if ($historyMessageCount !== null) {
            $agentCfg->resetChatHistory();
            $history = $agentCfg->getChatHistory();
            ChatHistoryTruncateHelper::truncateToMessageCount($history, $historyMessageCount);
        } else {
            EventBus::trigger(
                EventNameEnum::ORCHESTRATOR_RESUME_HISTORY_MISSING->value,
                $this,
                (new OrchestratorResumeHistoryMissingEventDto())
                    ->setSessionKey($this->configApp->getSessionKey())
                    ->setRunId($this->configApp->getSessionKey())
                    ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
                    ->setAgent($agentCfg)
                    ->setTodolistName($todoList->getName())
                    ->setLastCompletedTodoIndex($runStateDto->getLastCompletedTodoIndex())
                    ->setStartFromTodoIndex($startFromTodoIndex)
            );
        }

        return $startFromTodoIndex;
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
     * Возвращает true, если включено суммаризирование истории step-цикла через Skill.
     *
     * @return bool
     */
    private function isStepHistorySummarizationEnabled(): bool
    {
        return (bool) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_ENABLED, false);
    }

    /**
     * Создаёт сервис суммаризации шага на основе конфигурации приложения.
     *
     * Возвращает null, если фича выключена.
     */
    private function buildSummarizeServiceForStep(): ?SummarizeService
    {
        if (!$this->isStepHistorySummarizationEnabled()) {
            return null;
        }

        $useSkill  = (bool) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_USE_SKILL, false);
        $skillName = (string) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_SKILL, '');
        $skill     = $useSkill && $skillName !== '' ? $this->configApp->getSkill($skillName) : null;

        $mode = (string) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_MODE, 'replace_range');
        $role = strtolower(trim((string) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_ROLE, 'assistant'))) === 'system'
            ? MessageRole::SYSTEM
            : MessageRole::ASSISTANT;

        $minTranscriptChars = max(0, (int) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_MIN_TRANSCRIPT_CHARS, 50));
        $debug              = (bool) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_DEBUG, false);

        $filterTools        = (bool) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_FILTER_TOOL_MESSAGES, true);
        $filterHistoryTools = (bool) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_FILTER_HISTORY_TOOLS, true);
        $minMsgChars        = max(0, (int) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_FILTER_MIN_MESSAGE_CHARS, 3));
        $dedupConsecutive   = (bool) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_FILTER_DEDUP_CONSECUTIVE, true);

        return new SummarizeService(
            useSkill                    : $useSkill && $skill,
            skill                       : $skill instanceof \app\modules\neuron\classes\skill\Skill ? $skill : null,
            mode                        : $mode,
            role                        : $role,
            minTranscriptChars          : $minTranscriptChars,
            debug                       : $debug,
            logger                      : $debug ? $this->configApp->getLoggerWithContext() : null,
            filterToolMessages          : $filterTools,
            filterHistoryTools          : $filterHistoryTools,
            minMessageChars             : $minMsgChars,
            dedupConsecutive            : $dedupConsecutive,
            dedupTranscriptGlobal       : true,
            excludeLlmCycleHelperPrompts: true,
        );
    }
}
