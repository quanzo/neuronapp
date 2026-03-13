<?php

declare(strict_types=1);

namespace app\modules\neuron\traits;

use app\modules\neuron\classes\config\ConfigurationApp;

/**
 * Трейт для связи с конфигурацией приложения
 */
trait DependConfigAppTrait
{
    /**
     * Глобальная конфигурация приложения, используемая для разрешения агентов и зависимостей.
     */
    protected ?ConfigurationApp $configApp = null;

    /**
     * Устанавливает конфигурацию приложения для компонента.
     *
     * @param ConfigurationApp|null $configApp Экземпляр конфигурации приложения или null.
     *
     * @return static
     */
    public function setConfigurationApp(?ConfigurationApp $configApp): static
    {
        $this->configApp = $configApp;

        return $this;
    }

    /**
     * Возвращает конфигурацию приложения, если она была установлена.
     */
    public function getConfigurationApp(): ?ConfigurationApp
    {
        return $this->configApp;
    }

    /**
     * Возвращает базовый ключ сессии.
     */
    public function getSessionKey(): ?string
    {
        return $this->configApp->getSessionKey();
    }
}
