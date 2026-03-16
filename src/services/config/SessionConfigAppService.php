<?php

namespace app\modules\neuron\services\config;

use app\modules\neuron\classes\config\ConfigurationApp;

/**
 * Управления сессиями приложения
 */
class SessionConfigAppService
{
    public function __construct(protected ConfigurationApp $_configApp)
    {
        
    }
}
