<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\mind\storage\UserMindStorage;

/**
 * Контракт пересборки summary одной сессии `.mind`.
 *
 * Реализация: {@see \app\modules\neuron\mind\services\MindSessionSummaryService}.
 */
interface MindSessionSummaryRefresherInterface
{
    /**
     * Пересчитывает summary сессии и записывает его в `sessions.md`.
     *
     * @param ConfigurationApp $app Конфигурация приложения (агент, лимиты).
     * @param UserMindStorage    $mind Хранилище пользователя.
     * @param string             $sessionKey Ключ основной сессии (не служебный).
     */
    public function refreshSessionSummary(ConfigurationApp $app, UserMindStorage $mind, string $sessionKey): void;
}
