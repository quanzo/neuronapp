<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\helpers;

/**
 * Helper служебного sessionKey для LLM-суммаризации сессий `.mind`.
 *
 * Назначение
 * ----------
 * Вызовы агента-суммаризатора публикуют `agent.message.completed` с отдельным ключом,
 * чтобы служебные сообщения не попадали в основную сессию и не перезапускали
 * {@see \app\modules\neuron\mind\services\MindSessionSummaryService::refreshSessionSummary()}.
 *
 * Пример:
 *
 * <code>
 * $main = '20260602-120000-123456-501';
 * $summaryKey = MindSummarySessionKeyHelper::forMainSession($main);
 * // "20260602-120000-123456-501:__mind_summary__"
 * MindSummarySessionKeyHelper::isSummarySession($summaryKey); // true
 * MindSummarySessionKeyHelper::mainFromSummary($summaryKey); // $main
 * </code>
 */
final class MindSummarySessionKeyHelper
{
    /**
     * Суффикс служебной сессии mind-summary (не менять без миграции данных).
     */
    public const string SUFFIX = ':__mind_summary__';

    /**
     * Строит sessionKey для служебных LLM-вызовов суммаризации основной сессии.
     *
     * @param string $mainSessionKey Полный ключ основной сессии приложения.
     */
    public static function forMainSession(string $mainSessionKey): string
    {
        if ($mainSessionKey === '') {
            return 'unknown' . self::SUFFIX;
        }

        return $mainSessionKey . self::SUFFIX;
    }

    /**
     * Возвращает true, если ключ относится к служебной сессии суммаризации.
     *
     * @param string $sessionKey Ключ из DTO события или индекса sessions.md.
     */
    public static function isSummarySession(string $sessionKey): bool
    {
        if ($sessionKey === '') {
            return false;
        }

        return str_ends_with($sessionKey, self::SUFFIX);
    }

    /**
     * Извлекает основной sessionKey из служебного ключа или возвращает null.
     *
     * @param string $summarySessionKey Ключ служебной сессии.
     */
    public static function mainFromSummary(string $summarySessionKey): ?string
    {
        if (!self::isSummarySession($summarySessionKey)) {
            return null;
        }

        return substr($summarySessionKey, 0, -strlen(self::SUFFIX));
    }
}
