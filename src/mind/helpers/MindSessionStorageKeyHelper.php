<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\helpers;

use app\modules\neuron\helpers\StorageFileHelper;

/**
 * Helper формирования безопасного ключа хранения для файлов сессионной памяти `.mind`.
 *
 * Назначение
 * ----------
 * В новом формате `.mind` каждый sessionKey хранится в отдельном наборе файлов.
 * Чтобы избежать path-traversal и проблем с файловой системой, мы нормализуем ключ
 * в безопасный `storageKey`, разрешающий только `[a-zA-Z0-9_-]`.
 *
 * Важные инварианты:
 * - одинаковый sessionKey всегда даёт одинаковый storageKey;
 * - storageKey безопасен как часть имени файла/каталога;
 * - storageKey НЕ обязан быть читаемым на 100% — но должен быть стабильным.
 *
 * Пример:
 *
 * <code>
 * $storageKey = MindSessionStorageKeyHelper::fromSessionKey('20260602-120000-123456-501');
 * // например: "s_20260602-120000-123456-501"
 * </code>
 */
final class MindSessionStorageKeyHelper
{
    /**
     * Версия схемы (на случай будущих изменений формата).
     */
    private const string KEY_SCHEMA_PREFIX = 's_';

    /**
     * Строит безопасный storageKey из полного sessionKey.
     *
     * @param string $sessionKey Полный ключ сессии (как у {@see \app\modules\neuron\classes\config\ConfigurationApp::getSessionKey()}).
     */
    public static function fromSessionKey(string $sessionKey): string
    {
        $safe = StorageFileHelper::sanitizeFileKeyPart($sessionKey !== '' ? $sessionKey : 'unknown');

        return self::KEY_SCHEMA_PREFIX . $safe;
    }
}
