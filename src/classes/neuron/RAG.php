<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\neuron\nodes\LoggingChatNode;
use app\modules\neuron\traits\AgentUseModuleTrait;
use app\modules\neuron\traits\RagUseModuleTrait;
use NeuronAI\RAG\RAG as NeuronRAG;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Workflow\Node;

use function array_map;
use function is_array;

/**
 * Агент частично настраиваемый через модуль. Со знаниями в векторном хранилище.
 */
class RAG extends NeuronRAG
{
    use AgentUseModuleTrait;
    use RagUseModuleTrait;

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
                $agentCfg = $this->config instanceof ConfigurationAgent ? $this->config : null;
                return new LoggingChatNode(
                    $this->resolveProvider(),
                    $agentCfg,
                    $this->resolvePayloadLogMode()
                );
            }

            return $node;
        }, $nodes);

        parent::compose($nodes);
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
