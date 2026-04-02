<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\todo;

use Amp\Future;
use app\modules\neuron\classes\AbstractPromptWithParams;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\attachments\AttachmentDto;
use app\modules\neuron\classes\dto\cmd\AgentCmdDto;
use app\modules\neuron\classes\dto\cmd\CmdDto;
use app\modules\neuron\classes\dto\events\RunEventDto;
use app\modules\neuron\classes\dto\events\TodoEventDto;
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\helpers\AttachmentHelper;
use app\modules\neuron\helpers\ChatHistoryTruncateHelper;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\helpers\LlmCycleHelper;
use app\modules\neuron\interfaces\ITodo;
use app\modules\neuron\interfaces\ITodoList;
use app\modules\neuron\traits\HasNeedSkillsTrait;
use app\modules\neuron\traits\AttachesSkillToolsTrait;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message as NeuronMessage;

/**
 * Список заданий Todo, формируемый из текстового ввода.
 *
 * Использует общий парсер APromptComponent для разбора опций и тела,
 * а затем строит очередь заданий из текстового блока.
 * Задания хранятся в виде очереди и извлекаются по принципу FIFO.
 */
class TodoList extends AbstractPromptWithParams implements ITodoList
{
    use HasNeedSkillsTrait;
    use AttachesSkillToolsTrait;

    private const MAX_GOTO_TRANSITIONS = 100;

    /**
     * Очередь заданий в порядке FIFO.
     *
     * @var ITodo[]
     */
    private array $todos = [];

    /**
     * Создает список заданий на основе входного текста.
     *
     * Текст может содержать:
     *  - только блок заданий;
     *  - блок опций и блок заданий, разделенные линиями из '-';
     *  - только блок опций (без заданий);
     *  - быть пустым (без опций и заданий).
     *
     * @param string               $input     Полный текст описания списка.
     * @param string               $name      Имя списка заданий.
     * @param ConfigurationApp|null $configApp Экземпляр конфигурации приложения для разрешения зависимостей.
     */
    public function __construct(string $input, string $name = '', ?ConfigurationApp $configApp = null)
    {
        parent::__construct($input, $name, $configApp);
        $this->initTodos();
    }

    /**
     * Значение по умолчанию для опции pure_context.
     */
    protected function getDefaultPureContext(): bool
    {
        return false;
    }

    /**
     * Добавляет одно или несколько заданий в очередь.
     *
     * @param ITodo ...$todos Экземпляры заданий для добавления.
     */
    public function pushTodo(ITodo ...$todos): void
    {
        foreach ($todos as $todo) {
            $this->todos[] = $todo;
        }
    }

    /**
     * Извлекает одно задание из начала списка по принципу FIFO.
     *
     * @return ITodo|null Задание либо null, если список пуст.
     */
    public function popTodo(): ?ITodo
    {
        if ($this->todos === []) {
            return null;
        }

        /** @var ITodo $todo */
        $todo = array_shift($this->todos);

        return $todo;
    }

    /**
     * Возвращает массив заданий (копию списка) для итерации без изменения очереди.
     *
     * @return list<ITodo>
     */
    public function getTodos(): array
    {
        return array_values($this->todos);
    }

    /**
     * Выполняет все задания списка через переданную конфигурацию агента.
     *
     * При включённой истории чата создаётся и обновляется чекпоинт состояния run в .store;
     * после каждого успешно завершённого todo записывается last_completed_todo_index и
     * history_message_count для возможности resume с откатом истории.
     *
     * @param MessageRole              $role               Роль сообщений.
     * @param AttachmentDto[]          $attachments        Дополнительные вложения, передаваемые с каждым заданием.
     * @param array<string,mixed>|null $params             Параметры, передаваемые в {@see ITodo::getTodo()}.
     * @param int                      $startFromTodoIndex Индекс первого todo для выполнения (0 = с начала); предыдущие пропускаются (для resume).
     * @param SessionParamsDto|null    $sessionParams      Сессионные параметры (date, branch, user и др.) для подстановки.
     * @param bool|null                $softContinue       Мы знаем, что сессия прервалась на пункте задачи. И не хотим отправлять задание снова, а просто хотим чтобы LLM продолжжила. Никаких гарантий продолжения тут нет. Но если пункт многоходовый...
     *
     * @return Future<ChatHistoryInterface> Завершается копией истории сообщений агента после выполнения всех заданий.
     */
    public function execute(
        MessageRole       $role               = MessageRole::USER,
        array             $attachments        = [],
        ?array            $params             = null,
        int               $startFromTodoIndex = 0,
        ?SessionParamsDto $sessionParams      = null,
        ?bool             $softContinue       = null
    ): Future {
        $agentCfg = $this->getConfigurationAgent();

        return \Amp\async(function () use ($agentCfg, $role, $attachments, $params, $startFromTodoIndex, $sessionParams, $softContinue): ChatHistoryInterface {
            $runId       = $this->generateRunId();

            $sessionCfg = $this->isPureContext() ? $agentCfg->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY) : $agentCfg;
            EventBus::trigger(
                EventNameEnum::RUN_STARTED->value,
                $this,
                $this->buildRunEventDto($sessionCfg, $runId, 0)->setSuccess(true)
            );

            // здесь передаем в конфигурацию сессии навыки, указанные в опции skills
            $this->attachSkillToolsToSession($sessionCfg, $role);

            $configApp = $this->getConfigurationApp();

            $runStateDto = null;
            $sessionKey  = $sessionCfg->getSessionKey();
            if ($sessionCfg->enableChatHistory && $sessionKey !== null && $sessionKey !== '') {
                $runStateDto = $sessionCfg->getBlankRunStateDto();
                $runStateDto->setTodolistName($this->getName());
                $runStateDto->write();
            }

            $todos            = $this->getTodos();
            $todoCount        = count($todos);
            $stepsExecuted    = 0;
            $currentTodoIndex = max(0, $startFromTodoIndex);
            $effectiveParams  = $this->buildEffectiveParams(
                $params,
                $sessionParams?->toArray()
            );
            while ($currentTodoIndex < $todoCount) {
                $todoIndex      = $currentTodoIndex;
                $todo           = $todos[$todoIndex];
                $todoTextRaw    = $todo->getTodo($effectiveParams);
                $todoTextToSend = $todoTextRaw;
                $todoSessionCfg = $sessionCfg;  // агент конкретно этой todo

                if ($todo instanceof Todo) {
                    $switchToAgentDto = $todo->getSwitchToAgent();
                    if ($switchToAgentDto) {
                        /**
                         * В пункте списка Todo задан агент через @@agent - его надо найти и использовать при исполнении этого $todo
                         */
                        $resolvedAgent = $configApp->getAgent($switchToAgentDto->getAgentName());
                        if ($resolvedAgent) {
                            // такой агент есть
                            // агент должен работать с той же историей что и $sessionCfg
                            $r = $resolvedAgent->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY); // агент с пустой историей
                            $r->setChatHistory($sessionCfg->getChatHistory()); // передаем ему историю текущего контекста исполнения
                            $r->tools       = $sessionCfg->getTools(); // и инструменты
                            $todoSessionCfg = $r;
                            EventBus::trigger(
                                EventNameEnum::TODO_AGENT_SWITCHED->value,
                                $this,
                                $this->buildTodoEventDto($sessionCfg, $runId, $todoIndex, $todoTextRaw)
                                    ->setTodoAgent($todoSessionCfg->getAgentName())
                                    ->setReason('agent_switched')
                            );
                        } else {
                            EventBus::trigger(
                                EventNameEnum::TODO_AGENT_SWITCHED->value,
                                $this,
                                $this->buildTodoEventDto($sessionCfg, $runId, $todoIndex, $todoTextRaw)
                                    ->setTodoAgent($switchToAgentDto->getAgentName())
                                    ->setReason('agent_not_found_use_default')
                            );
                        }
                    }
                }

                EventBus::trigger(
                    EventNameEnum::TODO_STARTED->value,
                    $this,
                    $this->buildTodoEventDto($sessionCfg, $runId, $todoIndex, $todoTextRaw)->setTodoAgent($todoSessionCfg->getAgentName())
                );
                try {
                    $message = new NeuronMessage($role, $todoTextToSend);
                    $todoAttachments = $attachments;
                    if ($configApp !== null) {
                        /**
                         * В тексте каждого элемента списка ищем указание на файл для его подключения в контекст исполнени именно этого todo
                         */
                        $contextFiles = AttachmentHelper::buildContextAttachments($todoTextToSend, $configApp);
                        if ($contextFiles['attachments'] !== []) {
                            $todoAttachments = array_merge($todoAttachments, $contextFiles['attachments']);
                        }
                    }
                    if (!$softContinue) {
                        $todoSessionCfg->sendMessageWithAttachments($message, $todoAttachments);
                    } else {
                        // мягкое продолжение - считаем что у LLM уже есть задание этого пункта. И поэтому запустим цикл вопрос-ответ на проверку готовности
                    }

                    // здесь проверим, что пункт LLM исполнила - спросим ее прямо
                    $arRes = LlmCycleHelper::waitCycle($todoSessionCfg, $todoSessionCfg->llmMaxCycleCount, $todoSessionCfg->llmMaxTotalRounds);

                    ++$stepsExecuted;

                    /**
                     * Здесь мы записываем какой пункт списка выполнили
                     */
                    if ($runStateDto !== null) {
                        $latestStateDto = $sessionCfg->getExistRunStateDto();
                        if ($latestStateDto !== null) {
                            $runStateDto
                                ->setGotoRequestedTodoIndex($latestStateDto->getGotoRequestedTodoIndex())
                                ->setGotoTransitionsCount($latestStateDto->getGotoTransitionsCount());
                        }
                        $runStateDto->setLastCompletedTodoIndex($todoIndex);
                        $runStateDto->setHistoryMessageCount(
                            ChatHistoryTruncateHelper::getMessageCount($todoSessionCfg->getChatHistory())
                        );
                        $runStateDto->write();
                    }
                    EventBus::trigger(
                        EventNameEnum::TODO_COMPLETED->value,
                        $this,
                        $this->buildTodoEventDto($sessionCfg, $runId, $todoIndex, $todoTextRaw)
                            ->setTodoAgent($todoSessionCfg->getAgentName())
                    );
                } catch (\Throwable $e) {
                    EventBus::trigger(
                        EventNameEnum::TODO_FAILED->value,
                        $this,
                        $this->buildTodoEventDto($sessionCfg, $runId, $todoIndex, $todoTextRaw)
                            ->setTodoAgent($todoSessionCfg->getAgentName())
                            ->setReason($e->getMessage())
                    );
                    EventBus::trigger(
                        EventNameEnum::RUN_FAILED->value,
                        $this,
                        $this->buildRunEventDto($sessionCfg, $runId, $stepsExecuted)
                            ->setType('todolist')
                            ->setName($this->getName())
                            ->setSuccess(false)
                            ->setErrorClass($e::class)
                            ->setErrorMessage($e->getMessage())
                    );
                    throw $e;
                }

                if ($runStateDto) {
                    $runStateDto = $sessionCfg->getExistRunStateDto() ?? $runStateDto;
                    $gotoRequestedTodoIndex = $runStateDto->getGotoRequestedTodoIndex();
                    if ($gotoRequestedTodoIndex) {
                        EventBus::trigger(
                            EventNameEnum::TODO_GOTO_REQUESTED->value,
                            $this,
                            $this->buildTodoEventDto($sessionCfg, $runId, $todoIndex, $todoTextRaw)
                                ->setGotoTargetIndex($gotoRequestedTodoIndex)
                                ->setGotoTransitionsCount($runStateDto->getGotoTransitionsCount())
                        );
                        // есть переход на пункт задачи
                        $gotoTransitionsCount = $runStateDto->getGotoTransitionsCount() + 1;

                        // сбросим факт перехода
                        $runStateDto
                            ->setGotoRequestedTodoIndex(null)
                            ->setGotoTransitionsCount($gotoTransitionsCount);

                        if ($gotoTransitionsCount > self::MAX_GOTO_TRANSITIONS) {
                            EventBus::trigger(
                                EventNameEnum::TODO_GOTO_REJECTED->value,
                                $this,
                                $this->buildTodoEventDto($sessionCfg, $runId, $todoIndex, $todoTextRaw)
                                    ->setGotoTargetIndex($gotoRequestedTodoIndex)
                                    ->setGotoTransitionsCount($gotoTransitionsCount)
                                    ->setReason('max_goto_transitions')
                            );
                            $runStateDto->write();
                            break;
                        }

                        if ($gotoRequestedTodoIndex < 0 || $gotoRequestedTodoIndex >= $todoCount) {
                            EventBus::trigger(
                                EventNameEnum::TODO_GOTO_REJECTED->value,
                                $this,
                                $this->buildTodoEventDto($sessionCfg, $runId, $todoIndex, $todoTextRaw)
                                    ->setGotoTargetIndex($gotoRequestedTodoIndex)
                                    ->setGotoTransitionsCount($gotoTransitionsCount)
                                    ->setReason('goto_target_out_of_range')
                            );
                            $runStateDto->write();
                            break;
                        }
                        $runStateDto->write();
                        $currentTodoIndex = $gotoRequestedTodoIndex; // устанавливаем целевой пункт
                    } else {
                        ++$currentTodoIndex;
                    }
                } else {
                    ++$currentTodoIndex;
                }
                if ($currentTodoIndex == $todoCount) { // индекс вышел за последний элемент
                    // LLM отработала задание и если сообщение последнее в цикле заданий, то надо, чтобы последнее сообщение истории было итоговым сообщением по заданиям
                    /* т.к. удаляем пару вопрос-ответ статус задачи то и итоговое сообщение будет последним в истории
                    LlmCycleHelper::repeateResultMsg($todoSessionCfg);
                    */
                }
            } // end while

            // спросим модель "Is the work finished? Answer only YES or NO!" "Сontinue with the task" "Repeat the final message" "Is the task complete? Answer only YES or NO! If NO, continue!"

            if ($runStateDto !== null) {
                $runStateDto->delete();
            }
            EventBus::trigger(
                EventNameEnum::RUN_FINISHED->value,
                $this,
                $this->buildRunEventDto($sessionCfg, $runId, $stepsExecuted)
                    ->setType('todolist')
                    ->setName($this->getName())
                    ->setSuccess(true)
            );

            return clone $sessionCfg->getChatHistory();
        });
    }

    /**
     * Инициализирует список заданий на основе текстового тела.
     *
     * Поддерживаются как нумерованные задания («1. Текст»), так и
     * единственное ненумерованное задание, если номеров нет вовсе.
     * Пустые строки в начале блока заданий пропускаются.
     */
    private function initTodos(): void
    {
        $body = $this->getBody();
        if ($body === '') {
            return;
        }

        $lines = explode("\n", $body);

        // Пропускаем начальные пустые строки
        while ($lines !== [] && trim(reset($lines)) === '') {
            array_shift($lines);
        }

        $hasNumbered = false;
        foreach ($lines as $line) {
            if (preg_match('/^\d+\.\s+/', $line) === 1) {
                $hasNumbered = true;
                break;
            }
        }

        if (!$hasNumbered) {
            $text = trim(implode("\n", $lines));
            if ($text !== '') {
                $this->pushTodo(Todo::fromString($text));
            }

            return;
        }

        $currentLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\d+)\.\s+(.*)$/', $line, $matches) === 1) {
                if ($currentLines !== []) {
                    $this->finalizeTodo($currentLines);
                    $currentLines = [];
                }

                $currentLines[] = $matches[2];
            } else {
                $currentLines[] = $line;
            }
        }

        if ($currentLines !== []) {
            $this->finalizeTodo($currentLines);
        }
    }

    /**
     * Завершает формирование одного задания и добавляет его в список.
     *
     * @param string[] $lines Строки текста одного задания.
     */
    private function finalizeTodo(array $lines): void
    {
        $text = rtrim(implode("\n", $lines), "\n");

        if ($text === '') {
            return;
        }

        $this->pushTodo(Todo::fromString($text));
    }

    /**
     * Создает DTO run-события для TodoList.
     */
    private function buildRunEventDto(ConfigurationAgent $agentCfg, string $runId, int $stepsExecuted): RunEventDto
    {
        $dto = new RunEventDto();
        $dto->setSessionKey($agentCfg->getSessionKey() ?? '');
        $dto->setRunId($runId);
        $dto->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $dto->setAgent($agentCfg);
        $dto->setType('todolist');
        $dto->setName($this->getName());
        $dto->setSteps($stepsExecuted);
        return $dto;
    }

    /**
     * Создает DTO todo-события.
     */
    private function buildTodoEventDto(ConfigurationAgent $agentCfg, string $runId, int $todoIndex, string $todoText): TodoEventDto
    {
        $dto = new TodoEventDto();
        $dto->setSessionKey($agentCfg->getSessionKey() ?? '');
        $dto->setRunId($runId);
        $dto->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $dto->setAgent($agentCfg);
        $dto->setTodoListName($this->getName());
        $dto->setTodoIndex($todoIndex);
        $dto->setTodo($todoText);
        return $dto;
    }

    /**
     * Генерирует идентификатор выполнения.
     */
    private function generateRunId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
