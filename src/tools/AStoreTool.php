<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\storage\StoreStorage;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\tools\StoreToolResultDto;
use JSON_UNESCAPED_UNICODE;

/**
 * Инструмент для работы с результатами в `.store`.
 */
class AStoreTool extends ATool
{
    protected function getStorage(): StoreStorage
    {
        $agentCfg = $this->getAgentCfg();
        return $agentCfg?->getStoreStorage() ?? ConfigurationApp::getInstance()->getStoreStorage();
    }

    protected function getSessionKey(): string
    {
        $agentCfg = $this->getAgentCfg();
        return $agentCfg?->getSessionKey() ?? ConfigurationApp::getInstance()->getSessionKey();
    }

    /**
     * Сериализует результат в JSON.
     *
     * @param StoreToolResultDto $dto DTO результата.
     * @return string JSON.
     */
    protected function resultJson(StoreToolResultDto $dto): string
    {
        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
