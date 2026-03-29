<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\tools\VarToolResultDto;
use app\modules\neuron\classes\storage\VarStorage;
use app\modules\neuron\helpers\JsonHelper;

/**
 * Инструмент для работы с результатами в `.store`.
 */
class AVarTool extends ATool
{
    protected function getStorage(): VarStorage
    {
        $agentCfg = $this->getAgentCfg();
        return $agentCfg?->getVarStorage() ?? ConfigurationApp::getInstance()->getVarStorage();
    }

    protected function getSessionKey(): string
    {
        $agentCfg = $this->getAgentCfg();
        return $agentCfg?->getSessionKey() ?? ConfigurationApp::getInstance()->getSessionKey();
    }

    /**
     * Сериализует результат в JSON.
     *
     * @param VarToolResultDto $dto DTO результата.
     * @return string JSON.
     */
    protected function resultJson(VarToolResultDto $dto): string
    {
        return JsonHelper::encodeThrow($dto->toArray());
    }
}
