<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces;

use app\modules\neuron\classes\config\ConfigurationApp;

/**
 * Интерфейс связи с ConfigurationApp
 *
 */
interface IDependConfigApp
{
    /**
     * Устанавливает конфигурацию приложения для компонента.
     *
     * @param ConfigurationApp|null $configApp Экземпляр конфигурации приложения или null.
     *
     * @return static
     */
    public function setConfigurationApp(?ConfigurationApp $configApp): static;
    
    /**
     * Возвращает конфигурацию приложения, если она была установлена.
     */
    public function getConfigurationApp(): ?ConfigurationApp;

    /**
     * Возвращает базовый ключ сессии.
     */
    public function getSessionKey(): ?string;
}
