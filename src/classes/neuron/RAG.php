<?php
declare(strict_types=1);

namespace app\modules\neuron\classes;

use app\modules\neuron\traits\AgentUseModuleTrait;
use app\modules\neuron\traits\RagUseModuleTrait;
use NeuronAI\RAG\RAG as NeuronRAG;

/**
 * Агент частично настраиваемый через модуль. Со знаниями в векторном хранилище.
 */
class RAG extends NeuronRAG {
    use AgentUseModuleTrait;
    use RagUseModuleTrait;
}
