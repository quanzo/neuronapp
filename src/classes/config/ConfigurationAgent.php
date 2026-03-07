<?php

namespace app\modules\neuron\classes\config;

use app\components\App;
use app\modules\neuron\classes\neuron\Agent;
use app\modules\neuron\classes\ChatHistory;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\neuron\RAG;
use app\modules\neuron\events\Event;
use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\helpers\CommentsHelper;
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
use app\modules\neuron\classes\logger\ContextualLogger;
use app\modules\neuron\tools\ATool;
use app\modules\neuron\traits\LoggerAwareContextualTrait;
use app\modules\neuron\traits\LoggerAwareTrait;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;


/**
 * Конфигурация агента для работы с LLM через Neuron PHP
 */
class ConfigurationAgent {
    use LoggerAwareTrait;
    use LoggerAwareContextualTrait;

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
     * Отправить сообщение в LLM без дополнительных вложений.
     *
     * Для передачи вложений (картинок, текстовых файлов и т.п.) используйте
     * {@see ConfigurationAgent::sendMessageWithAttachments()}.
     *
     * @param NeuronMessage $message
     * @return null|NeuronMessage|object null — сообщение не отправлено, либо объект сообщения-ответа,
     *                                   либо DTO, если ответ структурирован.
     */
    public function sendMessage(NeuronMessage $message): mixed {
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
    public function sendMessageWithAttachments(NeuronMessage $message, array $attachments = []): mixed {
        $agent = $this->getAgent();

        /**
         * @var Agent|RAG $agent
         */

        $start = microtime(true);

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

            if ($this->reponseStructClass) {
                $response = $agent->structured(
                    $message,
                    $this->reponseStructClass,
                    2
                );
            } else {
                $handler = $agent->chat($message);
                $response = $handler->getMessage();
            }
        } catch (\Throwable $e) {
            $this->getLogger()->error('Ошибка при отправке сообщения агенту', array_merge(
                $this->getLogContext(),
                ['exception' => $e]
            ));
            throw $e;
        }

        $duration = round(microtime(true) - $start, 2);

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
     * Агент для отправки сообщений в LLM.
     *
     * Экземпляр агента создается один раз и кешируется внутри конфигурации.
     * При наличии настроек RAG (embedding-провайдер и векторное хранилище)
     * используется агент с поддержкой RAG, иначе — обычный агент.
     *
     * @return AgentInterface Экземпляр агента, сконфигурированный текущими настройками.
     */
    public function getAgent(): AgentInterface {
        if ($this->_agent === null) {
            if (!empty($this->embeddingProvider) && !empty($this->vectorStore)) {
                $this->_agent = RAG::make();
            } else {
                $this->_agent = Agent::make();
                $this->_agent->toolMaxTries($this->toolMaxTries);
            }
            $this->_agent->config = $this;
        }
        return $this->_agent;
    }

    /**
     * Возвращает клон конфигурации с сброшенным кешем агента.
     * Используется для исполнения с дополнительными инструментами (например, skills)
     * без изменения основного состояния агента.
     *
     * @return self
     */
    public function cloneForSession(): self
    {
        $clone = clone $this;
        $clone->_agent = null;
        return $clone;
    }

    //-----------------------------------------

    /**
     * Провайдер для конфигурирования агента
     * ! не использовать впрямую - использовать методы агента
     * 
     * @return AIProviderInterface
     */
    public function getProvider(): AIProviderInterface {
        if ($this->provider instanceof AIProviderInterface) {
            return $this->provider;
        }
        if (CallableWrapper::isCallable($this->provider)) {
            return CallableWrapper::call($this->provider);
        }
        return $this->_provider;
    }

    /**
     * Инструкция для LLM
     */
    public function getInstructions() {
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
    public function getTools(): array {
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

        $loggerWithContext = $this->getLoggerWithContext();
        foreach ($tools as $tool) {
            if ($tool instanceof ATool) {
                $tool->setLogger($loggerWithContext);
            }
        }

        return $tools;
    }

    /**
     * Возвращает контекст для логирования: имя агента и ключ сессии.
     *
     * @return array{agent: string|null, session: string|null}
     */
    public function getLogContext(): array
    {
        return [
            'agent'   => $this->agentName,
            'session' => $this->getSessionKey(),
        ];
    }

    /**
     * Возвращает объект истории чата для текущей конфигурации агента.
     *
     * При включенной истории (`enableChatHistory === true`) используется файловое
     * хранилище {@see FileChatHistory}, сохраняющее сообщения в директории
     * {@see ConfigurationApp::getSessionDir()}.
     *
     * При отключенной истории (`enableChatHistory === false`) используется
     * оперативное хранилище {@see InMemoryChatHistory}, не сохраняющее сообщения
     * между запусками приложения.
     *
     * Созданный объект кешируется и переиспользуется при последующих вызовах.
     *
     * @return ChatHistoryInterface Экземпляр истории чата для текущего агента.
     */
    public function getChatHistory(): ChatHistoryInterface {
        if ($this->_chatHistory !== null) {
            return $this->_chatHistory;
        }

        if ($this->enableChatHistory) {
            $key = $this->sessionKey . '-' . ($this->agentName ?: 'unknown');

            $this->_chatHistory = new FileChatHistory(
                ConfigurationApp::getInstance()->getSessionDir(),
                $key,
                $this->contextWindow
            );
        } else {
            $this->_chatHistory = new InMemoryChatHistory($this->contextWindow);
        }

        return $this->_chatHistory;
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
    public function setChatHistory(ChatHistoryInterface $history): void {
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
    public function resetChatHistory(): void {
        $this->_chatHistory = null;
    }

    /**
     * Возвращает базовый ключ сессии (без имени агента).
     */
    public function getSessionKey(): ?string {
        return $this->sessionKey;
    }

    /**
     * Устанавливает базовый ключ сессии и сбрасывает кешированную историю чата.
     */
    public function setSessionKey(?string $sessionKey): void {
        $this->sessionKey = $sessionKey;
        $this->resetChatHistory();
    }

    /**
     * Провайдер для создания эмбеддингов
     * ! для агента
     */
    public function getEmbeddingProvider() {
        if (CallableWrapper::isCallable($this->embeddingProvider)) {
            return CallableWrapper::call($this->embeddingProvider);
        }
        return $this->embeddingProvider;
    }

    /**
     * Векторная база данных.
     * ! Для инициалиции RAG
     */
    public function getVectorStore() {
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
     *  - mcp (array)
     *  - embeddingProvider (EmbeddingsProviderInterface|callable|null)
     *  - embeddingChunkSize (int)
     *  - vectorStore (VectorStoreInterface|callable|null)
     *
     * @param array<string, mixed> $cfg        Ассоциативный массив с настройками агента.
     * @param string|null          $sessionKey Базовый ключ сессии (без имени агента).
     *                                         Если null — генерируется через ConfigurationApp.
     *
     * @return ConfigurationAgent|null Экземпляр конфигурации или null при пустом массиве.
     */
    public static function makeFromArray(array $cfg, ?string $sessionKey = null): ?ConfigurationAgent {
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

        $config->setSessionKey($sessionKey ?? ConfigurationApp::buildSessionKey());

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
     * @param string|null $sessionKey Базовый ключ сессии (без имени агента).
     *                                Если null — генерируется через ConfigurationApp.
     *
     * @return ConfigurationAgent|null Экземпляр конфигурации или null, если файл не найден
     *                                 или не удалось корректно разобрать его содержимое.
     */
    public static function makeFromFile(string $filename, ?string $sessionKey = null): ?ConfigurationAgent {
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

                $cleanJson = CommentsHelper::stripComments($contents);
                $decoded = json_decode($cleanJson, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    return null;
                }

                $configArray = $decoded;
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

        return self::makeFromArray($configArray, $sessionKey);
    }
}
