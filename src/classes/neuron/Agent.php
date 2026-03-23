<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron;

use app\modules\neuron\classes\logger\NullLogger;
use app\modules\neuron\classes\neuron\nodes\LoggingChatNode;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\traits\AgentUseModuleTrait;
use NeuronAI\Agent\Agent as NeuronAIAgent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Workflow\Node;
use Psr\Log\LoggerInterface;

/**
 * Агент частично настраиваемый через модуль
 */
class Agent extends NeuronAIAgent
{
    use AgentUseModuleTrait;

    /**
     * Подменяет стандартный ChatNode на логирующий узел приложения.
     *
     * @param Node|Node[] $nodes Набор узлов текущего режима.
     *
     * @return void
     */
    protected function compose(array|Node $nodes): void
    {
        $nodes = is_array($nodes) ? $nodes : [$nodes];

        $nodes = array_map(function (Node $node): Node {
            if ($node instanceof ChatNode) {
                return new LoggingChatNode(
                    $this->resolveProvider(),
                    $this->resolveLogger(),
                    $this->resolvePayloadLogMode()
                );
            }

            return $node;
        }, $nodes);

        parent::compose($nodes);
    }

    /**
     * Возвращает логгер из конфигурации агента.
     *
     * @return LoggerInterface
     */
    private function resolveLogger(): LoggerInterface
    {
        if (isset($this->config) && $this->config instanceof ConfigurationAgent) {
            return $this->config->getLoggerWithContext();
        }

        return new NullLogger();
    }

    /**
     * Возвращает режим логирования payload.
     *
     * @return string
     */
    private function resolvePayloadLogMode(): string
    {
        if (isset($this->config) && $this->config instanceof ConfigurationAgent) {
            return $this->config->llmPayloadLogMode;
        }

        return 'summary';
    }
}
