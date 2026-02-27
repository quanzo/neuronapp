<?php

namespace app\modules\neuron;

use app\components\App;
use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\classes\Agent;
use app\modules\neuron\classes\ChatHistory;
use app\modules\neuron\classes\RAG;
use app\modules\neuron\events\Event;
use app\modules\neuron\models\Message;
use NeuronAI\AgentInterface;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use Stringable;


/**
 * Конфигурация агента для работы с LLM через Neuron PHP
 */
class ConfigurationAgent {

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
    
    protected $_provider = null;
    protected $_agent;

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
     * Агент для отправки
     *
     * @return AgentInterface
     */
    public function getAgent(): AgentInterface {
        if (empty($this->_agent)) {
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
     * История чата
     *
     * @return ChatHistoryInterface
     */
    public function getChatHistory(): ChatHistoryInterface {
        $chatHistoryStore = new InMemoryChatHistory($this->contextWindow);
        return $chatHistoryStore;
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
}
