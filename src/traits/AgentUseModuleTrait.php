<?php
namespace app\modules\neuron\traits;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Providers\AIProviderInterface;

trait AgentUseModuleTrait {
    public $config;

    public function instructions(): string
    {
        return (string)$this->config->getInstructions();
    }

    protected function provider(): AIProviderInterface
    {
        return $this->config->getProvider();
    }

    public function tools(): array
    {
        return $this->config->getTools();
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return $this->config->getChatHistory();
    }
}
