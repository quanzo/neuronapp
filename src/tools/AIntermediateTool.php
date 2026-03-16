<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\storage\IntermediateStorage;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\tools\IntermediateToolResultDto;
use \JSON_UNESCAPED_UNICODE;

/**
 * Инструмент для работы с промежуточными данными
 *
 */
class AIntermediateTool extends ATool
{
    protected function getStorage(): IntermediateStorage {
        $agentCfg = $this->getAgentCfg();
        return $agentCfg?->getIntermediateStorage() ?? ConfigurationApp::getInstance()->getIntermediateStorage();
    }

    protected function getSessionKey(): string {
        $agentCfg = $this->getAgentCfg();
        return $agentCfg?->getSessionKey() ?? ConfigurationApp::getInstance()->getSessionKey();
    }

    /**
     * Сериализует результат в JSON.
     *
     * @param IntermediateToolResultDto $dto DTO результата.
     * @return string JSON.
     */
    protected function resultJson(IntermediateToolResultDto $dto): string
    {
        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
