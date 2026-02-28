<?php

namespace app\modules\neuron;

use app\components\App;
use app\modules\neuron\classes\Agent;
use app\modules\neuron\classes\ChatHistory;
use app\modules\neuron\classes\RAG;
use app\modules\neuron\events\Event;
use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\models\Message;
use NeuronAI\AgentInterface;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use RuntimeException;
use Stringable;


/**
 * Конфигурация агента для работы с LLM через Neuron PHP
 */
class ConfigurationAgent {

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
     * Отправить сообщение в LLM
     *
     * @param NeuronMessage $message
     * @return null|NeuronMessage|object - null сообщение не отправлено или объект сообщения-ответа или dto если ответ структурирован
     */
    public function sendMessage(NeuronMessage $message): mixed {
        $agent = $this->getAgent();

        $start = microtime(true);
        
        $isStruct = false;
        if ($this->reponseStructClass) {
            $response = $agent->structured(
                $message,
                $this->reponseStructClass,
                2
            );
            $isStruct = true;
        } else {
            $response = $agent->chat($message);
        }
        $duration = round(microtime(true) - $start, 2);
    
        // логирование
        $chatHistory = $agent->resolveChatHistory();
        /**
         * @var ChatHistory $chatHistory
         */
        $historyModel = $chatHistory->getHistoryModel();
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

        return $tools;
    }

    /**
     * Возвращает объект истории чата для текущей конфигурации агента.
     *
     * При включенной истории (`enableChatHistory === true`) используется файловое
     * хранилище {@see FileChatHistory}, сохраняющее сообщения в поддиректории
     * `.sessions` рабочей директории приложения.
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
            $directory = APP_WORK_DIR . DIRECTORY_SEPARATOR . '.sessions';
            $key = $this->buildSessionKey();

            $this->_chatHistory = new FileChatHistory(
                $directory,
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
     * Формирует уникальный ключ сессии истории сообщений для агента.
     *
     * Ключ строится по правилу:
     *  `<YYYYMMDD-HHMMSS-μs>-<agentName>`,
     * где часть с датой и временем формируется на основе текущего microtime.
     *
     * Если имя агента не задано, используется строка "unknown".
     *
     * @return string Уникальный ключ истории сообщений.
     */
    protected function buildSessionKey(): string {
        $microtime = microtime(true);
        $dt = \DateTime::createFromFormat('U.u', sprintf('%.6f', $microtime));

        if ($dt === false) {
            $dt = new \DateTime();
        }

        $agentName = $this->agentName ?: 'unknown';

        return $dt->format('Ymd-His-u') . '-' . $agentName;
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
     * @param array<string, mixed> $cfg Ассоциативный массив с настройками агента.
     *
     * @return ConfigurationAgent|null Экземпляр конфигурации или null при пустом массиве.
     */
    public static function makeFromArray(array $cfg): ?ConfigurationAgent {
        if ($cfg === []) {
            return null;
        }

        $config = new self();

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
     * @param string $filename Абсолютный путь к файлу конфигурации агента.
     *
     * @return ConfigurationAgent|null Экземпляр конфигурации или null, если файл не найден
     *                                 или не удалось корректно разобрать его содержимое.
     */
    public static function makeFromFile(string $filename): ?ConfigurationAgent {
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

        return self::makeFromArray($configArray);
    }
}
