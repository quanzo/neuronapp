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
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\helpers\ChatHistoryEditHelper;
use app\modules\neuron\helpers\ChatHistoryRollbackHelper;
use app\modules\neuron\helpers\ChatHistoryTruncateHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
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
    private const CFG_STEP_HISTORY_SUMMARY_ENABLED = 'orchestrator.step_history_summarize.enabled';
    private const CFG_STEP_HISTORY_SUMMARY_SKILL = 'orchestrator.step_history_summarize.skill';

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
                    $stepHistoryCountBefore = null;
                    if ($this->isStepHistorySummarizationEnabled()) {
                        $history = $stepTodoList->getConfigurationAgent()->getChatHistory();
                        $stepHistoryCountBefore = ChatHistoryRollbackHelper::getSnapshotCount($history);
                    }

                    $this->executeTodoList($stepTodoList, $sessionParams);
                    $iterations = $i;

                    if ($stepHistoryCountBefore !== null) {
                        $history = $stepTodoList->getConfigurationAgent()->getChatHistory();
                        $stepHistoryCountAfter = ChatHistoryRollbackHelper::getSnapshotCount($history);
                        $stepMessagesCopy = ChatHistoryEditHelper::copyMessagesBySnapshotRange(
                            $history,
                            $stepHistoryCountBefore,
                            $stepHistoryCountAfter
                        );
                        $this->summarizeAndReplaceStepRangeIfConfigured(
                            $stepTodoList,
                            $stepHistoryCountBefore,
                            $stepHistoryCountAfter,
                            $stepMessagesCopy
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
     * Если включено в конфиге, создаёт summary по копии сообщений одного шага и заменяет только диапазон шага.
     *
     * @param TodoList $stepTodoList TodoList шага (нужен, чтобы получить агента/историю).
     * @param int $countBefore Размер истории до выполнения одной итерации step.
     * @param int $countAfter Размер истории после выполнения одной итерации step.
     * @param array<int, \NeuronAI\Chat\Messages\Message> $stepMessagesCopy Копия сообщений, появившихся во время этой итерации step.
     */
    private function summarizeAndReplaceStepRangeIfConfigured(
        TodoList $stepTodoList,
        int $countBefore,
        int $countAfter,
        array $stepMessagesCopy
    ): void {
        if (!$this->isStepHistorySummarizationEnabled()) {
            return;
        }

        $skillName = (string) $this->configApp->get(self::CFG_STEP_HISTORY_SUMMARY_SKILL, '');
        if ($skillName === '') {
            return;
        }

        $skill = $this->configApp->getSkill($skillName);
        if (!$skill instanceof Skill) {
            return;
        }

        $agentCfg = $stepTodoList->getConfigurationAgent();
        $skill->setDefaultConfigurationAgent($agentCfg);

        $transcript = $this->renderTranscript($stepMessagesCopy);
        $summaryRaw = $skill->execute(MessageRole::USER, [], ['transcript' => $transcript])->await();
        $summary = $this->normalizeSummaryToString($summaryRaw);
        if (trim($summary) === '') {
            return;
        }

        $history = $agentCfg->getChatHistory();
        $summaryMessage = new NeuronMessage(MessageRole::ASSISTANT, $summary);
        ChatHistoryEditHelper::replaceMessagesBySnapshotRange($history, $countBefore, $countAfter, $summaryMessage);
    }

    /**
     * Формирует простой транскрипт из массива сообщений.
     *
     * @param array<int, \NeuronAI\Chat\Messages\Message> $messages
     */
    private function renderTranscript(array $messages): string
    {
        $lines = [];
        foreach ($messages as $m) {
            $role = (string) $m->getRole();
            $content = (string) ($m->getContent() ?? '');
            $lines[] = sprintf("[%s]\n%s", $role, $content);
        }

        return implode("\n\n", $lines);
    }

    /**
     * Нормализует результат выполнения skill к строке summary.
     */
    private function normalizeSummaryToString(mixed $summaryRaw): string
    {
        if ($summaryRaw instanceof NeuronMessage) {
            return (string) ($summaryRaw->getContent() ?? '');
        }
        if (is_string($summaryRaw)) {
            return $summaryRaw;
        }
        if ($summaryRaw instanceof \JsonSerializable) {
            $encoded = json_encode($summaryRaw->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded !== false ? $encoded : '';
        }
        if (is_array($summaryRaw)) {
            $encoded = json_encode($summaryRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded !== false ? $encoded : '';
        }

        return (string) $summaryRaw;
    }
}
