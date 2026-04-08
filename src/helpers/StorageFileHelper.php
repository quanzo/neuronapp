<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Единая точка истины для имён файлов и префиксов служебных артефактов проекта.
 *
 * Используется для построения имён файлов:
 * - истории сессий в `.sessions`;
 * - checkpoint run-state в `.store`;
 * - результатов и индексов `VarStorage`.
 *
 * Пример:
 * <code>
 * $history = StorageFileHelper::sessionHistoryFileName('20250301-143022-123456-0');
 * $runState = StorageFileHelper::runStateFileName('20250301-143022-123456-0', 'session');
 * $varFile = StorageFileHelper::varResultFileName('20250301-143022-123456-0', 'completed');
 * </code>
 */
final class StorageFileHelper
{
    public const SESSION_HISTORY_PREFIX    = 'neuron_';
    public const SESSION_HISTORY_EXTENSION = '.chat';
    public const RUN_STATE_PREFIX          = 'run_state_';
    public const VAR_RESULT_PREFIX         = 'var_';
    public const VAR_INDEX_PREFIX          = 'var_index_';
    public const JSON_EXTENSION            = '.json';
    public const LOG_EXTENSION             = '.log';

    /**
     * Строит имя файла истории сессии в `.sessions`.
     *
     * Если имя агента не задано, используется общий формат для всей сессии:
     * `neuron_<sessionKey>.chat`.
     * Если имя агента передано явно, оно добавляется суффиксом `-<agentName>`.
     *
     * @param string      $sessionKey Базовый ключ сессии.
     * @param string|null $agentName  Имя агента или null для общего файла сессии.
     *
     * @return string Имя файла без пути.
     */
    public static function sessionHistoryFileName(string $sessionKey, ?string $agentName = null): string
    {
        $key = self::sanitizeFileKeyPart($sessionKey);

        if ($agentName !== null) {
            $normalizedAgentName = self::sanitizeFileKeyPart($agentName !== '' ? $agentName : 'unknown');
            $key .= '-' . $normalizedAgentName;
        }

        return self::SESSION_HISTORY_PREFIX . $key . self::SESSION_HISTORY_EXTENSION;
    }

    /**
     * Строит имя файла checkpoint run-state в `.store`.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $agentName  Имя агента.
     *
     * @return string Имя файла без пути.
     */
    public static function runStateFileName(string $sessionKey, string $agentName): string
    {
        $safeKey = self::sanitizeFileKeyPart($sessionKey);
        $safeAgent = self::sanitizeFileKeyPart($agentName !== '' ? $agentName : 'unknown');

        return self::RUN_STATE_PREFIX . $safeKey . '_' . $safeAgent . self::JSON_EXTENSION;
    }

    /**
     * Строит имя файла результата VarStorage в `.store`.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $name       Имя сохранённой переменной.
     *
     * @return string Имя файла без пути.
     */
    public static function varResultFileName(string $sessionKey, string $name): string
    {
        $safeKey = self::sanitizeFileKeyPart($sessionKey);
        $safeName = self::sanitizeFileKeyPart($name);

        return self::VAR_RESULT_PREFIX . $safeKey . '_' . $safeName . self::JSON_EXTENSION;
    }

    /**
     * Строит имя индекс-файла VarStorage в `.store`.
     *
     * @param string $sessionKey Базовый ключ сессии.
     *
     * @return string Имя файла без пути.
     */
    public static function varIndexFileName(string $sessionKey): string
    {
        $safeKey = self::sanitizeFileKeyPart($sessionKey);

        return self::VAR_INDEX_PREFIX . $safeKey . self::JSON_EXTENSION;
    }

    /**
     * Нормализует часть имени файла.
     *
     * Разрешены только символы `[a-zA-Z0-9_-]`, остальные заменяются на `_`.
     *
     * @param string $value Исходная строка.
     *
     * @return string Безопасная часть имени файла.
     */
    public static function sanitizeFileKeyPart(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $value);

        return is_string($safe) ? $safe : '';
    }

    /**
     * Строит имя лог-файла для сессии.
     *
     * @param string $sessionKey Ключ сессии.
     *
     * @return string Имя лог-файла без пути.
     */
    public static function sessionLogFileName(string $sessionKey): string
    {
        return self::sanitizeFileKeyPart($sessionKey) . self::LOG_EXTENSION;
    }
}
