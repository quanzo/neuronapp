<?php
declare(strict_types=1);

namespace app\modules\neuron\classes;

use app\modules\neuron\traits\AgentUseModuleTrait;
use NeuronAI\Agent as NeuronAIAgent;

/**
 * Агент частично настраиваемый через модуль
 */
class Agent extends NeuronAIAgent {
    use AgentUseModuleTrait;
}
