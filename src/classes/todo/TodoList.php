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
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\classes\logger\RunLogger;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\helpers\AttachmentHelper;
use app\modules\neuron\helpers\ChatHistoryTruncateHelper;
use app\modules\neuron\helpers\CommentsHelper;
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

    /**
     * Имя команды переключения агента в тексте todo.
     */
    private const CMD_AGENT = 'agent';

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
     *
     * @return Future<ChatHistoryInterface> Завершается копией истории сообщений агента после выполнения всех заданий.
     */
    public function execute(
        MessageRole $role = MessageRole::USER,
        array $attachments = [],
        ?array $params = null,
        int $startFromTodoIndex = 0,
        ?SessionParamsDto $sessionParams = null
    ): Future {
        $agentCfg = $this->getConfigurationAgent();

        return \Amp\async(function () use ($agentCfg, $role, $attachments, $params, $startFromTodoIndex, $sessionParams): ChatHistoryInterface {
            $logger      = $agentCfg->getLoggerWithContext();
            $baseContext = ['todolist' => $this->getName()];
            $runLogger   = new RunLogger($logger);
            $runId       = $runLogger->startRun('todolist', $this->getName(), $baseContext);
            $logger->info('TodoList started', $baseContext);

            $sessionCfg = $this->isPureContext() ? $agentCfg->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY) : $agentCfg;

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

            $todos = $this->getTodos();
            $stepsExecuted = 0;
            $effectiveParams = $this->buildEffectiveParams(
                $params,
                $sessionParams?->toArray()
            );
            foreach ($todos as $todoIndex => $todo) {
                if ($todoIndex < $startFromTodoIndex) {
                    continue;
                }
                $todoTextRaw    = $todo->getTodo($effectiveParams);
                $todoTextToSend = $todoTextRaw;
                $todoSessionCfg = $sessionCfg; // агент конкретно этой todo

                if ($todo instanceof Todo) {
                    $switchToAgentDto = $todo->getSwitchToAgent();
                    if ($switchToAgentDto) {
                        $resolvedAgent = $configApp->getAgent($switchToAgentDto->getAgentName());
                        if ($resolvedAgent) {
                            // такой агент есть
                            // агент должен работать с той же историей что и $sessionCfg
                            $r = $resolvedAgent->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY); // агент с пустой историей
                            $r->setChatHistory($sessionCfg->getChatHistory()); // передаем ему историю текущего контекста исполнения
                            $r->tools       = $sessionCfg->getTools(); // и интструменты
                            $todoSessionCfg = $r;
                        } else {
                            // агента нет
                            $sessionCfg->getLoggerWithContext()->warning('Агент из @@agent(...) не найден — используется агент по умолчанию', [
                                'todolist' => $this->getName(),
                                'agent' => $switchToAgentDto->getAgentName(),
                            ]);
                        }
                    }
                }

                $logger->info('Todo started', array_merge($baseContext, [
                    'todo_index' => $todoIndex,
                    'todo'       => $todoTextRaw,
                    'todo_agent' => $todoSessionCfg->getAgentName(),
                ]));
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
                    $todoSessionCfg->sendMessageWithAttachments($message, $todoAttachments);
                    ++$stepsExecuted;

                    /**
                     * Здесь мы записываем какой пункт списка выполнили
                     */
                    if ($runStateDto !== null) {
                        $runStateDto->setLastCompletedTodoIndex($todoIndex);
                        $runStateDto->setHistoryMessageCount(
                            ChatHistoryTruncateHelper::getMessageCount($todoSessionCfg->getChatHistory())
                        );
                        $runStateDto->write();
                    }
                    $logger->info('Todo completed', array_merge($baseContext, ['todo_index' => $todoIndex]));
                } catch (\Throwable $e) {
                    $logger->error('Ошибка выполнения todo', array_merge($baseContext, ['todo_index' => $todoIndex, 'exception' => $e]));
                    throw $e;
                }
            }

            if ($runStateDto !== null) {
                $runStateDto->delete();
            }

            $logger->info('TodoList completed', $baseContext);
            $runLogger->finishRun($runId, ['steps' => $stepsExecuted]);

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
}
