<?php

namespace app\modules\neuron\classes\config;

use app\components\App;
use app\modules\neuron\classes\neuron\Agent;
use app\modules\neuron\classes\ChatHistory;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\neuron\RAG;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\events\Event;
use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\helpers\ChatHistoryCopyHelper;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\models\Message;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use app\modules\neuron\classes\dto\attachments\AttachmentDto;
use app\modules\neuron\classes\dto\events\AgentMessageEventDto;
use app\modules\neuron\classes\dto\run\RunStateDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\logger\ContextualLogger;
use app\modules\neuron\classes\neuron\providers\LoggingAIProviderDecorator;
use app\modules\neuron\classes\neuron\history\FileFullChatHistory;
use app\modules\neuron\classes\neuron\trimmers\CclCodeHistoryTrimmer;
use app\modules\neuron\classes\neuron\trimmers\ConfigurationAgentHistoryHeadSummarizer;
use app\modules\neuron\classes\neuron\trimmers\FluidContextWindowTrimmer;
use app\modules\neuron\classes\neuron\trimmers\TokenCounter;
use app\modules\neuron\helpers\AttachmentHelper;
use app\modules\neuron\helpers\RunStateCheckpointHelper;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\interfaces\IAttachmentFile;
use app\modules\neuron\interfaces\IDependConfigApp;
use app\modules\neuron\classes\storage\VarStorage;
use app\modules\neuron\classes\WaitSuccess;
use app\modules\neuron\exceptions\RunStateNotFoundException;
use app\modules\neuron\helpers\ChatHistoryRollbackHelper;
use app\modules\neuron\helpers\ChatHistoryTruncateHelper;
use app\modules\neuron\helpers\LlmCycleHelper;
use app\modules\neuron\tools\ATool;
use app\modules\neuron\traits\DependConfigAppTrait;
use app\modules\neuron\traits\LoggerAwareContextualTrait;
use app\modules\neuron\traits\LoggerAwareTrait;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

/**
 * Конфигурация агента для работы с LLM через Neuron PHP
 */
class ConfigurationAgent implements IDependConfigApp
{
    use LoggerAwareTrait;
    use LoggerAwareContextualTrait;
    use DependConfigAppTrait;

    /**
     * Имя агента, для которого используется данная конфигурация.
     *
     * Используется при формировании ключа истории сообщений.
     *
     * @var string|null
     */
    public ?string $agentName = null;

    /**
     * Включить историю переписки через таблицу в БД
     *
     * @var boolean
     */
    public $enableChatHistory = true;

    /**
     * Размер контекста для LLM
     *
     * ! Например, gpt-oss = 128к; devstral-small-2 = 384к
     * ! Надо учесть, что история обрезается до размеров контеста LLM. Иными словами, хранится только размер контекста!
     *
     * @var integer
     */
    public $contextWindow = 50000;

    /**
     * Этот параметр переключает диалог модуля на определенную историю сообщений.
     * Иначе берется просто последняя история.
     * ! Использовать осторожно ибо в ChatHistory нет проверки на модуль/доступ пользователя. Так сделано специально, чтобы была возможность "прыгать" по чатам
     *
     * @var null|int
     */
    public $history_id = null;

    /**
     * Здесь укажем класс, если хотим чтобы LLM возвращала ответ в виде структур DTO.
     * ! В классе обязательно реализовать Stringable JsonSerializable
     *
     * @var null|string
     */
    public $reponseStructClass = null;

    /**
     * Провайдер нейронки
     *
     * @var array|callable
     */
    public $provider = [];

    /**
     * Кешированный провайдер LLM.
     *
     * @var AIProviderInterface|null
     */
    protected ?AIProviderInterface $_provider = null;

    /**
     * Экземпляр агента, связанный с данной конфигурацией.
     *
     * @var AgentInterface|null
     */
    protected ?AgentInterface $_agent = null;

    /**
     * Кешированный объект истории чата для данной конфигурации.
     *
     * @var ChatHistoryInterface|null
     */
    protected ?ChatHistoryInterface $_chatHistory = null;

    /**
     * Базовый ключ сессии (без имени агента).
     *
     * Имя агента добавляется при формировании итогового ключа
     * в {@see getChatHistory()}.
     *
     * @var string|null
     */
    protected ?string $sessionKey = null;

    /**
     * Хранилище результатов для данного агента.
     */
    protected ?VarStorage $varStorage = null;

    /**
     * Системный промпт
     *
     * @var Stringable|string|callable - поддерживается CallableWrapper
     */
    public $instructions = '';

    /**
     * Дополнительные инструменты, которые может использовать LLM
     *
     * @see vendor/neuron-core/neuron-ai/src/Tools/Toolkits/Calculator/DivideTool.php
     * @var array<ToolInterface|ToolkitInterface|ProviderToolInterface>|array<callable>|callable - поддержка CallableWrapper
     */
    public $tools = [];

    /**
     * Сколько раз можно вызвать инструмент
     */
    public $toolMaxTries = 5;

    /**
     * Включает логирование системного промпта и payload запроса к LLM.
     *
     * @var bool
     */
    public bool $enableLlmPayloadLogging = false;

    /**
     * Режим детализации логирования payload.
     * Поддерживаются значения: summary|debug.
     *
     * @var string
     */
    public string $llmPayloadLogMode = 'summary';

    /**
     * Для рабочего цикла задает максимум явных ответов «ещё в работе».
     * Максимальное количество итераций, которые будут выполнены в цикле вопрос-ответ статуса задачи LLM.
     *
     * @var integer
     */
    public int $llmMaxCycleCount = 10;

    /**
     * Это максимальное количество всех итераций, даже когда LLM не будет внятно отвечать свой статус.
     *
     * @var integer
     */
    public int $llmMaxTotalRounds = 60;

    /**
     * MCP серверы.
     *
     * Здесь надо сконфигурировать NeuronAI\MCP\McpConnector. Дальше инструменты от MCP сервера будут добавлены в список инструментов.
     */
    public $mcp = [];

    /**
     * Сервис который будет рассчитывать вектора текста
     *
     *
     * @var EmbeddingsProviderInterface|null|callable - поддержка CallableWrapper
     */
    public $embeddingProvider = null;

    /**
     * Размер чанка для формирования векторного представления. Он зависит от используемой embedding модели.
     * ! Здесь размер в символах, а не в токенах.
     * ! Надо понимать, что это значение надо использовать, когда сам будешь разбивать текст на куски и считать вектора. Но можно использовать и любое свое, исходя из необходимости
     *
     * @var integer
     */
    public $embeddingChunkSize = 1500;

    /**
     * Векторное хранилище
     *
     * Например, у embeddinggemma размер вектора = 768. Соответственно, размер вектора в хранилище должен быть таким же.
     * Если размерность не будет правильной, то может быть ошибка.
     *
     * ! драйверы векторных харнилищ есть в библиотеке neuron-ai
     *
     * @var VectorStoreInterface|null|callable
     */
    public $vectorStore = null;

    /**
     * препроцессоры
     *
     * !В конфиге конфигурируем через CallableWrapper
     *
     * @var PreProcessorInterface[]
     */
    public array $preProcessors = [];

    /**
     * постобработка
     *
     * !В конфиге конфигурируем через CallableWrapper
     *
     * @var PostProcessorInterface[]
     */
    public array $postProcessors = [];

    /**
     * Отправить сообщение в LLM без дополнительных вложений.
     *
     * Для передачи вложений (картинок, текстовых файлов и т.п.) используйте
     * {@see ConfigurationAgent::sendMessageWithAttachments()}.
     *
     * @param NeuronMessage $message
     * @return null|NeuronMessage|object null — сообщение не отправлено, либо объект сообщения-ответа,
     *                                   либо DTO, если ответ структурирован.
     */
    public function sendMessage(NeuronMessage $message): mixed
    {
        return $this->sendMessageWithAttachments($message, []);
    }

    /**
     * Отправить сообщение в LLM с дополнительными вложениями.
     *
     * Вложения прикрепляются к сообщению как content blocks NeuronAI.\n
     * Поддерживаются два формата элементов массива:\n
     * - {@see AttachmentDto} (будет преобразован в {@see ContentBlockInterface} через getContentBlock())\n
     * - {@see ContentBlockInterface} (будет добавлен напрямую)\n
     *
     * @param NeuronMessage         $message     Основное сообщение диалога.
     * @param array<int,AttachmentDto|ContentBlockInterface> $attachments Вложения (по умолчанию — пустой массив).
     *
     * @return null|NeuronMessage|object null — сообщение не отправлено, либо объект сообщения-ответа,
     *                                   либо DTO, если ответ структурирован.
     */
    public function sendMessageWithAttachments(NeuronMessage $message, array $attachments = []): mixed
    {
        $attachments      = AttachmentHelper::deduplicateAttachments($attachments);
        $agent            = $this->getAgent();
        $attachmentsCount = count($attachments);
        $isStructured     = $this->reponseStructClass !== null && $this->reponseStructClass !== '';

        /**
         * @var Agent|RAG $agent
         */

        $start = microtime(true);
        EventBus::trigger(
            EventNameEnum::AGENT_MESSAGE_STARTED->value,
            $this,
            $this->buildAgentMessageEventDto($attachmentsCount, $isStructured)
                ->setSuccess(true)
                ->setDurationSeconds(0.0)
        );

        try {
            // В NeuronAI вложения прикрепляются к сообщению через content blocks.
            foreach ($attachments as $attachment) {
                if ($attachment instanceof AttachmentDto) {
                    $message->addContent($attachment->getContentBlock());
                    continue;
                }

                if ($attachment instanceof ContentBlockInterface) {
                    $message->addContent($attachment);
                    continue;
                }
            }

            $history               = $this->getChatHistory();
            $countBefore           = ChatHistoryRollbackHelper::getSnapshotCount($history);
            $response              = null;
            $llmRetryDelayMicrosec = 100000;
            $maxLlmAttempts        = 5;
            $isCycleRequest        = LlmCycleHelper::isCycleRequestMsg($message);


            try {
                $msg = $message;
                if ($this->reponseStructClass) {
                    $response = $agent->structured(
                        $msg,
                        $this->reponseStructClass,
                        2
                    );
                } else {
                    $handler = $agent->chat($msg);
                    $response = $handler->getMessage();
                }
            } catch (\Throwable $e) {
                // попробуем оживить
                $arCycles = LlmCycleHelper::waitCycleAgent($agent, 5, 7);
                if (!empty($arCycles['error'])) {
                    throw new RuntimeException(
                        !empty($arCycles['errorMsg']) ? $arCycles['errorMsg'] : 'Ошибка при отправке сообщения в чат'
                    );
                }
            }

            // Флаг о том что была ошибка запроса
/*            $isReqErrFound = false;

            WaitSuccess::waitSuccess(
                // ---
                function () use (&$response, $message, $agent, &$isReqErrFound) {
                    $msg = $message;
                    if ($this->reponseStructClass) {
                        $response = $agent->structured(
                            $msg,
                            $this->reponseStructClass,
                            2
                        );
                    } else {
                        $handler = $agent->chat($msg);
                        $response = $handler->getMessage();
                    }
                    $isReqErrFound = false;
                },

                $llmRetryDelayMicrosec,
                $maxLlmAttempts,

                function (\Throwable $e, int $execCount) use ($history, $countBefore, $maxLlmAttempts, &$isReqErrFound, $message): void {
                    $isReqErrFound = true;

                    $advMsg = '';
                    /*
                    ChatHistoryRollbackHelper::rollbackToSnapshot($history, $countBefore);
                    $advMsg = ' история откатана';

                    Допустим, не будем чистить историю ибо там контент из инструментов и это все повторится
                    /

                    // execCount — индекс неудачной попытки (0 при первом сбое), как в WaitSuccess.
                    $failedAttempt = $execCount + 1;
                    $willRetry     = $failedAttempt < $maxLlmAttempts;

                    $msg = 'Вызов LLM завершился ошибкой: ' . $e->getMessage() . ';' . $advMsg;
                    $msg .= $willRetry ? '; запланирован повтор' : '; повторов больше не будет';

                    $this->getLogger()->warning(
                        $msg,
                        array_merge($this->getLogContext(), [
                            'llm_retry'     => true,
                            'failedAttempt' => $failedAttempt,
                            'maxAttempts'   => $maxLlmAttempts,
                            'willRetry'     => $willRetry,
                            'errorClass'    => $e::class,
                            'errorMessage'  => $e->getMessage(),
                        ])
                    );

                    // попробуем оживить
                    //$arCycles = LlmCycleHelper::waitCycle($this, 2, 4);

                }
                // ---
            );*/
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $start, 2);
            EventBus::trigger(
                EventNameEnum::AGENT_MESSAGE_FAILED->value,
                $this,
                $this->buildAgentMessageEventDto($attachmentsCount, $isStructured)
                    ->setSuccess(false)
                    ->setDurationSeconds($duration)
                    ->setErrorClass($e::class)
                    ->setErrorMessage($e->getMessage())
            );
            $this->getLogger()->error('Ошибка при отправке сообщения агенту', array_merge(
                $this->getLogContext(),
                ['exception' => $e]
            ));
            throw $e;
        }

        $duration = round(microtime(true) - $start, 2);
        EventBus::trigger(
            EventNameEnum::AGENT_MESSAGE_COMPLETED->value,
            $this,
            $this->buildAgentMessageEventDto($attachmentsCount, $isStructured)
                ->setSuccess(true)
                ->setDurationSeconds($duration)
        );

        /**
         * @var Agent|RAG $agent
         *
         */

        // логирование
        // В NeuronAI агент может хранить историю внутри состояния.
        // Метод getChatHistory() не является частью AgentInterface, поэтому вызываем безопасно.
        if (method_exists($agent, 'getChatHistory')) {
            /** @var mixed $chatHistory */
            $chatHistory = $agent->getChatHistory();
            unset($chatHistory);
        }
        return $response;
    }

    /**
     * Создаёт DTO события отправки сообщения агентом.
     */
    private function buildAgentMessageEventDto(int $attachmentsCount, bool $isStructured): AgentMessageEventDto
    {
        return (new AgentMessageEventDto())
            ->setSessionKey($this->getSessionKey() ?? '')
            ->setRunId('')
            ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
            ->setAgent($this)
            ->setAttachmentsCount($attachmentsCount)
            ->setStructured($isStructured);
    }

    protected function makeListObjects($arCfg)
    {
        $res = [];
        if (!empty($arCfg)) {
            foreach ($arCfg as $el) {
                if (is_array($el) && CallableWrapper::isCallable($el)) {
                    $res[] = CallableWrapper::call($el);
                } elseif (is_object($el)) {
                    $res[] = $el;
                }
            }
        }
        return $res;
    }

    /**
     * Агент для отправки сообщений в LLM.
     *
     * Экземпляр агента создается один раз и кешируется внутри конфигурации.
     * При наличии настроек RAG (embedding-провайдер и векторное хранилище)
     * используется агент с поддержкой RAG, иначе — обычный агент.
     *
     * @return AgentInterface Экземпляр агента, сконфигурированный текущими настройками.
     */
    public function getAgent(): AgentInterface
    {
        if ($this->_agent === null) {
            if (!empty($this->embeddingProvider) && !empty($this->vectorStore)) {
                $this->_agent = RAG::make();

                // препроцессоры и постпроцессоры
                $this->_agent->setPreProcessors($this->makeListObjects($this->preProcessors));
                $this->_agent->setPostProcessors($this->makeListObjects($this->postProcessors));
            } else {
                $this->_agent = Agent::make();
            }
            $this->_agent->toolMaxTries($this->toolMaxTries);
            $this->_agent->config = $this;
        }
        return $this->_agent;
    }

    /**
     * Возвращает клон конфигурации с сброшенным кешем агента и новой in-memory историей чата.
     *
     * Используется для исполнения с дополнительными инструментами (например, skills) без изменения
     * основного состояния агента. Поведение истории чата управляется режимом клонирования:
     * - {@see ChatHistoryCloneMode::RESET_EMPTY} — создать новую пустую {@see InMemoryChatHistory};
     * - {@see ChatHistoryCloneMode::COPY_CONTEXT} — создать новую {@see InMemoryChatHistory} и
     *   перенести в неё все сообщения из текущей истории (файловой или in-memory).
     *
     * @param ChatHistoryCloneMode $mode Режим клонирования истории чата.
     *
     * @return self Клон конфигурации агента для отдельной сессии.
     */
    public function cloneForSession(ChatHistoryCloneMode $mode = ChatHistoryCloneMode::RESET_EMPTY): self
    {
        $clone         = clone $this;
        $clone->_agent = null;
        $targetHistory = null;

        if ($this->enableChatHistory) {
            $configApp = $this->getConfigurationApp();
            if ($configApp->get('pure_history.save', true)) {
                // историю временного чата запишем
                $targetHistory = new FileFullChatHistory(
                    ConfigurationApp::getInstance()->getSessionDir(),
                    $this->getSessionKey(),
                    $this->contextWindow,
                    '_',
                    '-' . (string)time() . '_' . rand(1, 999) . '_.chat',
                    new HistoryTrimmer(new TokenCounter())
                );
            }
        }

        if (empty($targetHistory)) {
            $targetHistory = new InMemoryChatHistory($this->contextWindow);
        }

        if ($mode === ChatHistoryCloneMode::COPY_CONTEXT) {
            $sourceHistory = $this->_chatHistory ?? $this->getChatHistory();
            ChatHistoryCopyHelper::copy($sourceHistory, $targetHistory);
        }

        $clone->setChatHistory($targetHistory);

        return $clone;
    }

    //-----------------------------------------

    /**
     * Провайдер для конфигурирования агента
     * ! не использовать впрямую - использовать методы агента
     *
     * @return AIProviderInterface
     */
    public function getProvider(): AIProviderInterface
    {
        if ($this->provider instanceof AIProviderInterface) {
            return $this->wrapProviderWithLogging($this->provider);
        }
        if (CallableWrapper::isCallable($this->provider)) {
            /** @var AIProviderInterface $provider */
            $provider = CallableWrapper::call($this->provider);
            return $this->wrapProviderWithLogging($provider);
        }

        if ($this->_provider === null) {
            throw new RuntimeException('LLM provider is not configured.');
        }

        return $this->wrapProviderWithLogging($this->_provider);
    }

    /**
     * Оборачивает провайдер декоратором логирования payload, если это включено в конфигурации.
     *
     * @param AIProviderInterface $provider Базовый провайдер.
     *
     * @return AIProviderInterface
     */
    private function wrapProviderWithLogging(AIProviderInterface $provider): AIProviderInterface
    {
        if (!$this->enableLlmPayloadLogging) {
            return $provider;
        }

        if ($provider instanceof LoggingAIProviderDecorator) {
            return $provider;
        }

        return new LoggingAIProviderDecorator(
            $provider,
            $this->getLoggerWithContext(),
            $this->llmPayloadLogMode
        );
    }

    /**
     * Инструкция для LLM
     */
    public function getInstructions()
    {
        if (CallableWrapper::isCallable($this->instructions)) {
            return CallableWrapper::call($this->instructions);
        }
        return (string) $this->instructions;
    }

    /**
     * Дополнительные инструменты для использования LLM.
     * ! конфигурирование агента - не использовать вне модуля
     *
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    public function getTools(): array
    {
        $tools = [];
        if (CallableWrapper::isCallable($this->tools)) {
            $tools = CallableWrapper::call($this->tools);
        }
        if ($this->tools && is_array($this->tools)) {
            $_tools = array_map(function ($val) {
                if (CallableWrapper::isCallable($val)) {
                    return CallableWrapper::call($val);
                }
                return $val;
            }, $this->tools);
            if ($_tools) {
                array_push($tools, ...$_tools);
            }
        }

        // инструменты от MCP серверов
        if ($this->mcp && is_array($this->mcp)) {
            $_mcp = array_map(function ($val) {
                if (CallableWrapper::isCallable($val)) {
                    return CallableWrapper::call($val);
                }
                return $val;
            }, $this->mcp);

            // в списке mcp должны быть соотв классы
            $_mcp = array_filter($_mcp, function ($val) {
                return $val instanceof McpConnector;
            });

            // из всех mcp соберем их инструменты
            foreach ($_mcp as $mcpConnector) {
                /**
                 * @var McpConnector $mcpConnector
                 */
                $t = $mcpConnector->tools();
                if ($t) {
                    array_push($tools, ...$t);
                }
            }
        }

        foreach ($tools as $tool) {
            if ($tool instanceof ATool) {
                /**
                 * В инструмент пробрасываем агента, который будет его использовать.
                 */
                $tool->setAgentCfg($this);
            }
        }

        // инструменты должны быть уникальны по имени
        $arToolsUniq = [];
        foreach ($tools as $toolObj) {
            $name = null;
            if (is_object($toolObj) && method_exists($toolObj, 'getName')) {
                $name = $toolObj->getName();
            }

            if (!is_string($name) || $name === '') {
                // Fallback: уникальный ключ, чтобы не потерять инструмент из-за неожиданной реализации.
                $name = is_object($toolObj) ? $toolObj::class . '#' . spl_object_id($toolObj) : 'tool#' . uniqid('', true);
            }

            $arToolsUniq[$name] = $toolObj;
        }

        return array_values($arToolsUniq);
    }

    /**
     * Возвращает контекст для логирования: имя агента и ключ сессии.
     *
     * @return array{agent: string|null}
     */
    public function getLogContext(): array
    {
        return [
            'agent'   => $this->getAgentName(),
        ];
    }

    /**
     * Возвращает объект истории чата для текущей конфигурации агента.
     *
     * При включенной истории (`enableChatHistory === true`) используется файловое
     * хранилище {@see FileFullChatHistory}, сохраняющее сообщения в директории
     * {@see ConfigurationApp::getSessionDir()}.
     *
     * При отключенной истории (`enableChatHistory === false`) используется
     * оперативное хранилище {@see InMemoryChatHistory}, не сохраняющее сообщения
     * между запусками приложения.
     *
     *
     * @return ChatHistoryInterface Экземпляр истории чата для текущего агента.
     */
    public function getChatHistory(): ChatHistoryInterface
    {
        if ($this->_chatHistory !== null) {
            return $this->_chatHistory;
        }

        if ($this->enableChatHistory) {
            /**
             * Агенты могут быть на разных моделях, с разным размером контекстного окна. Но они используют одну историю сессии. И они не должны образать историю сообщений из-за своего размера контекстного окна.
             */
            $this->_chatHistory = new FileFullChatHistory(
                ConfigurationApp::getInstance()->getSessionDir(),
                $this->getSessionKey(), // контекст теперь для всей сессии, а не для агента в сессии
                $this->contextWindow,
                'neuron_',
                '.chat',
                $this->buildHistoryTrimmer()
            );
            /*
            $this->_chatHistory = new FileChatHistory(
                ConfigurationApp::getInstance()->getSessionDir(),
                $this->getSessionKey(), // контекст теперь для всей сессии, а не для агента в сессии
                $this->contextWindow
            );
            */
        } else {
            $this->_chatHistory = new InMemoryChatHistory($this->contextWindow);
        }

        return $this->_chatHistory;
    }

    /**
     * Создаёт триммер истории сообщений для формирования окна LLM.
     *
     * Триммер выбирается из конфигурации приложения:
     * - `history.trimmer = fluid` (по умолчанию) — {@see FluidContextWindowTrimmer};
     * - `history.trimmer = ccl_compact` — {@see CclCodeHistoryTrimmer} (microcompact + LLM-summary головы).
     *
     * Дополнительно можно настроить параметры:
     * - `history.ccl_compact.tail_ratio` (float, default 0.6)
     * - `history.ccl_compact.keep_recent_tool_results` (int, default 10)
     *
     * @return \NeuronAI\Chat\History\HistoryTrimmerInterface
     */
    private function buildHistoryTrimmer(): \NeuronAI\Chat\History\HistoryTrimmerInterface
    {
        $configApp = $this->getConfigurationApp() ?? ConfigurationApp::getInstance();
        $mode = (string) $configApp->get('history.trimmer', 'fluid');

        if ($mode === 'ccl_compact') {
            $tailRatio = (float) $configApp->get('history.ccl_compact.tail_ratio', 0.6);
            $keepRecent = (int) $configApp->get('history.ccl_compact.keep_recent_tool_results', 10);

            $summarizer = new ConfigurationAgentHistoryHeadSummarizer($this);
            return (new CclCodeHistoryTrimmer(new TokenCounter(), $summarizer))
                ->withTailRatio($tailRatio)
                ->withKeepRecentToolResults($keepRecent);
        }

        return new FluidContextWindowTrimmer();
    }

    /**
     * Заменяет текущий объект истории чата на переданный экземпляр.
     *
     * Метод позволяет внешнему коду установить произвольную реализацию
     * {@see ChatHistoryInterface} для текущего агента, например, чтобы
     * переиспользовать существующую историю или внедрить свою стратегию хранения.
     *
     * @param ChatHistoryInterface $history Новый объект истории чата.
     *
     * @return void
     */
    public function setChatHistory(ChatHistoryInterface $history): void
    {
        $this->_chatHistory = $history;
    }

    /**
     * Принудительно сбрасывает текущую историю чата.
     *
     * При следующем вызове {@see ConfigurationAgent::getChatHistory()} будет создан
     * новый экземпляр соответствующей реализации истории.
     *
     * @return void
     */
    public function resetChatHistory(): void
    {
        $this->_chatHistory = null;
    }

    /**
     * Возвращает базовый ключ сессии (без имени агента).
     */
    public function getSessionKey(): ?string
    {
        return $this->sessionKey;
    }

    /**
     * Устанавливает базовый ключ сессии и сбрасывает кешированную историю чата.
     */
    public function setSessionKey(?string $sessionKey): void
    {
        $this->sessionKey = $sessionKey;
        $this->resetChatHistory();
    }

    /**
     * Возвращает хранилище результатов для агента.
     *
     * По умолчанию делегирует в ConfigurationApp::getVarStorage().
     */
    public function getVarStorage(): VarStorage
    {
        if ($this->varStorage !== null) {
            return $this->varStorage;
        }

        $configApp = $this->getConfigurationApp();
        if ($configApp !== null) {
            $this->varStorage = $configApp->getVarStorage();
        } else {
            $this->varStorage = ConfigurationApp::getInstance()->getVarStorage();
        }

        return $this->varStorage;
    }

    /**
     * Имя агента
     *
     * @return string
     */
    public function getAgentName(): string
    {
        return $this->agentName ?: 'unknown';
    }

    /**
     * Возвращает чистое состояние исполнения todolist для агента.
     *
     * @return RunStateDto
     */
    public function getBlankRunStateDto(): RunStateDto
    {
        $runStateDto = (new RunStateDto())
            ->setSessionKey($this->getSessionKey())
            /*
            ->setAgentName($this->getAgentName())
            */
            ->setAgentName(RunStateDto::DEF_AGENT_NAME) // делаем так, чтобы состояние теперь было на всю сессию. для обесепечения работы разных агентов в одной сессии
            ->setRunId($this->getSessionKey())
            ->setStartedAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
            ->setLastCompletedTodoIndex(-1)
            ->setHistoryMessageCount(null)
            ->setGotoRequestedTodoIndex(null)
            ->setGotoTransitionsCount(0)
            ->setFinished(false);
        return $runStateDto;
    }

    /**
     * Возвращает состояние исполнения todolist для агента.
     *
     * Если исполнение предыдущего задания не завершено, то это будет видно в RunStateDto
     *
     * @return RunStateDto|null
     */
    public function getExistRunStateDto(): ?RunStateDto
    {
        /*
        $unfinishedCheckpoint = RunStateCheckpointHelper::read($this->getSessionKey(), $this->getAgentName());
        */
        $unfinishedCheckpoint = RunStateCheckpointHelper::read($this->getSessionKey()); // статус запуска на всю сессию теперь будет
        if ($unfinishedCheckpoint) {
            // есть не завершенный чекпоинт
            return $unfinishedCheckpoint;
        }
        return null;
    }

    /**
     * Возобновить исполнение списка с пункта, на котором прервалось.
     *
     * Откатывает историю "до последнего сообщения".
     *
     * @return boolean
     * @throws RunStateNotFoundException
     */
    public function resumeRunState(): bool
    {
        $runStateDto = $this->getExistRunStateDto();
        if ($runStateDto) {
            $historyMessageCount = $runStateDto->getHistoryMessageCount();
            if ($historyMessageCount !== null) {
                $this->resetChatHistory();
                $history = $this->getChatHistory();
                ChatHistoryTruncateHelper::truncateToMessageCount($history, $historyMessageCount);
                return true;
            }
        } else {
            throw new RunStateNotFoundException();
        }
        return false;
    }

    /**
     * Убрать состояние исполнения списка
     *
     * @return boolean
     * @throws RunStateNotFoundException
     */
    public function abortRunState(): bool
    {
        $runStateDto = $this->getExistRunStateDto();
        if ($runStateDto) {
            $runStateDto->delete();
            return true;
        } else {
            throw new RunStateNotFoundException();
        }
    }

    /**
     * Провайдер для создания эмбеддингов
     * ! для агента
     */
    public function getEmbeddingProvider()
    {
        if (CallableWrapper::isCallable($this->embeddingProvider)) {
            return CallableWrapper::call($this->embeddingProvider);
        }
        return $this->embeddingProvider;
    }

    /**
     * Векторная база данных.
     * ! Для инициалиции RAG
     */
    public function getVectorStore()
    {
        if (CallableWrapper::isCallable($this->vectorStore)) {
            return CallableWrapper::call($this->vectorStore);
        }
        return $this->vectorStore;
    }

    /**
     * Создает конфигурацию агента на основе ассоциативного массива настроек.
     *
     * Метод принимает массив с ключами, соответствующими публичным свойствам конфигурации,
     * и заполняет новый экземпляр {@see ConfigurationAgent} значениями из этого массива.
     * Неизвестные ключи игнорируются.
     *
     * Пример ожидаемой структуры массива:
     *  - enableChatHistory (bool)
     *  - contextWindow (int)
     *  - history_id (int|null)
     *  - reponseStructClass (string|null)
     *  - provider (array|callable)
     *  - instructions (string|Stringable|callable)
     *  - tools (array|callable)
     *  - toolMaxTries (int)
     *  - enableLlmPayloadLogging (bool)
     *  - llmPayloadLogMode (string: summary|debug)
     *  - mcp (array)
     *  - embeddingProvider (EmbeddingsProviderInterface|callable|null)
     *  - embeddingChunkSize (int)
     *  - vectorStore (VectorStoreInterface|callable|null)
     *
     * @param array<string, mixed> $cfg        Ассоциативный массив с настройками агента.
     * @param ConfigurationApp     $configApp конфигурация приложения
     *
     * @return ConfigurationAgent|null Экземпляр конфигурации или null при пустом массиве.
     */
    public static function makeFromArray(array $cfg, ConfigurationApp $configApp): ?ConfigurationAgent
    {
        if ($cfg === []) {
            return null;
        }

        $config = new self();

        if (array_key_exists('agentName', $cfg)) {
            $config->agentName = $cfg['agentName'] === null ? null : (string) $cfg['agentName'];
        }

        if (array_key_exists('logger', $cfg) && $cfg['logger'] instanceof LoggerInterface) {
            $config->setLogger($cfg['logger']);
        }

        if (array_key_exists('enableChatHistory', $cfg)) {
            $config->enableChatHistory = (bool) $cfg['enableChatHistory'];
        }

        if (array_key_exists('contextWindow', $cfg)) {
            $config->contextWindow = (int) $cfg['contextWindow'];
        }

        if (array_key_exists('history_id', $cfg)) {
            $historyId = $cfg['history_id'];
            $config->history_id = $historyId === null ? null : (int) $historyId;
        }

        if (array_key_exists('reponseStructClass', $cfg)) {
            $config->reponseStructClass = $cfg['reponseStructClass'] ?: null;
        }

        if (array_key_exists('provider', $cfg)) {
            $config->provider = $cfg['provider'];
        }

        if (array_key_exists('instructions', $cfg)) {
            $config->instructions = $cfg['instructions'];
        }

        if (array_key_exists('tools', $cfg)) {
            $config->tools = $cfg['tools'];
        }

        if (array_key_exists('toolMaxTries', $cfg)) {
            $config->toolMaxTries = (int) $cfg['toolMaxTries'];
        }

        if (array_key_exists('enableLlmPayloadLogging', $cfg)) {
            $config->enableLlmPayloadLogging = (bool) $cfg['enableLlmPayloadLogging'];
        }

        if (array_key_exists('llmPayloadLogMode', $cfg)) {
            $mode = (string) $cfg['llmPayloadLogMode'];
            $config->llmPayloadLogMode = $mode === 'debug' ? 'debug' : 'summary';
        }

        if (array_key_exists('mcp', $cfg)) {
            $config->mcp = $cfg['mcp'];
        }

        if (array_key_exists('embeddingProvider', $cfg)) {
            $config->embeddingProvider = $cfg['embeddingProvider'];
        }

        if (array_key_exists('embeddingChunkSize', $cfg)) {
            $config->embeddingChunkSize = (int) $cfg['embeddingChunkSize'];
        }

        if (array_key_exists('vectorStore', $cfg)) {
            $config->vectorStore = $cfg['vectorStore'];
        }

        $config->setConfigurationApp($configApp);
        $config->setSessionKey($configApp->getSessionKey());

        return $config;
    }

    /**
     * Создает конфигурацию агента на основе файла настроек.
     *
     * Поддерживаются два формата:
     *  - PHP-файл, возвращающий массив конфигурации (как в примере agents/neuron1.php);
     *  - JSONC-файл (JSON с комментариями), содержащий аналогичную структуру настроек.
     *
     * Приоритет формата задается внешней логикой (например, {@see AgentProducer}),
     * сам метод просто обрабатывает переданный путь.
     *
     * @param string      $filename   Абсолютный путь к файлу конфигурации агента.
     * @param ConfigurationApp $configApp конфигурация приложения
     *
     * @return ConfigurationAgent|null Экземпляр конфигурации или null, если файл не найден
     *                                 или не удалось корректно разобрать его содержимое.
     */
    public static function makeFromFile(string $filename, ConfigurationApp $configApp): ?ConfigurationAgent
    {
        if ($filename === '' || !is_file($filename)) {
            return null;
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        try {
            if ($extension === 'php') {
                /** @var mixed $configArray */
                $configArray = include $filename;
            } elseif ($extension === 'jsonc' || $extension === 'json') {
                $contents = @file_get_contents($filename);
                if ($contents === false) {
                    return null;
                }

                $cleanJson   = CommentsHelper::stripComments($contents);
                $configArray = JsonHelper::tryDecodeAssociativeArray($cleanJson);
                if ($configArray === null) {
                    return null;
                }
            } else {
                // Неподдерживаемое расширение файла.
                return null;
            }
        } catch (\Throwable $e) {
            // При любой ошибке загрузки/парсинга возвращаем null, чтобы не падать в рантайме.
            return null;
        }

        if (!is_array($configArray)) {
            return null;
        }

        return self::makeFromArray($configArray, $configApp);
    }
}
