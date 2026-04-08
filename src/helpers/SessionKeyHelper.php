<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use DateTime;

/**
 * Единая точка истины для формата session key.
 *
 * Определяет:
 * - канонический regex валидации;
 * - человекочитаемое описание формата;
 * - пример значения;
 * - генерацию base key и полного ключа с userId.
 *
 * Пример:
 * <code>
 * $baseKey = SessionKeyHelper::buildBaseKey();
 * $sessionKey = SessionKeyHelper::buildSessionKey(42);
 *
 * if (SessionKeyHelper::isValid($sessionKey)) {
 *     // ключ можно использовать в CLI и файловых артефактах
 * }
 * </code>
 */
final class SessionKeyHelper
{
    /**
     * Человекочитаемое описание канонического формата.
     */
    public const FORMAT = 'Ymd-His-u-userId';

    /**
     * Пример полного session key.
     */
    public const EXAMPLE = '20250301-143022-123456-0';

    /**
     * Канонический regex полного session key.
     */
    public const PATTERN = '/^\d{8}-\d{6}-\d+-\d+$/';

    /**
     * Regex базовой части ключа без userId.
     */
    public const BASE_KEY_PATTERN = '/^\d{8}-\d{6}-\d+$/';

    /**
     * Генерирует базовую часть ключа сессии без userId.
     *
     * @return string Строка вида `YYYYMMDD-HHMMSS-uuuuuu`.
     */
    public static function buildBaseKey(): string
    {
        $microtime = microtime(true);
        $dateTime = DateTime::createFromFormat('U.u', sprintf('%.6f', $microtime));

        if ($dateTime === false) {
            $dateTime = new DateTime();
        }

        return $dateTime->format('Ymd-His-u');
    }

    /**
     * Строит полный session key, добавляя в конец userId.
     *
     * @param int|string $userId Идентификатор пользователя; пустое значение нормализуется в `0`.
     *
     * @return string Полный session key.
     */
    public static function buildSessionKey(int|string $userId = 0): string
    {
        return self::appendUserId(self::buildBaseKey(), $userId);
    }

    /**
     * Добавляет userId к уже сформированной базовой части ключа.
     *
     * @param string     $baseKey Базовая часть ключа без userId.
     * @param int|string $userId  Идентификатор пользователя.
     *
     * @return string Полный session key.
     */
    public static function appendUserId(string $baseKey, int|string $userId = 0): string
    {
        $normalizedUserId = trim((string) $userId);
        if ($normalizedUserId === '') {
            $normalizedUserId = '0';
        }

        return $baseKey . '-' . $normalizedUserId;
    }

    /**
     * Проверяет, соответствует ли строка каноническому формату полного session key.
     *
     * @param string $sessionKey Проверяемый ключ.
     *
     * @return bool true, если ключ валиден.
     */
    public static function isValid(string $sessionKey): bool
    {
        return preg_match(self::PATTERN, $sessionKey) === 1;
    }

    /**
     * Проверяет базовую часть session key без userId.
     *
     * @param string $baseKey Проверяемая базовая часть.
     *
     * @return bool true, если базовая часть валидна.
     */
    public static function isValidBaseKey(string $baseKey): bool
    {
        return preg_match(self::BASE_KEY_PATTERN, $baseKey) === 1;
    }

    /**
     * Возвращает короткое описание формата для CLI и docs.
     *
     * @return string Строка вида `Ymd-His-u-userId (например, 20250301-143022-123456-0)`.
     */
    public static function describeFormat(): string
    {
        return self::FORMAT . ' (например, ' . self::EXAMPLE . ')';
    }
}
