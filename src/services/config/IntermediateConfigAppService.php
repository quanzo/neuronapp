<?php

namespace app\modules\neuron\services\config;

use app\modules\neuron\classes\config\ConfigurationApp;

/**
 * Сервис управления промежуточными intermediate данными приложения
 */
class IntermediateConfigAppService
{
    public function __construct(protected ConfigurationApp $_configApp)
    {
    }
}
