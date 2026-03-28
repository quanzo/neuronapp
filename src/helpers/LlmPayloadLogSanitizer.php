<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use Stringable;

use function get_debug_type;
use function in_array;
use function is_array;
use function is_object;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function sprintf;

/**
 * Хелпер безопасной подготовки payload LLM для логирования.
 *
 * Нормализует произвольные структуры данных, маскирует чувствительные ключи
 * и ограничивает размер текстовых полей, чтобы не раздувать логи.
 *
 * Пример использования:
 * `$safe = LlmPayloadLogSanitizer::sanitize($payload, 1200, 6);`
 */
final class LlmPayloadLogSanitizer
{
    /**
     * Список паттернов ключей, значения которых должны быть скрыты.
     *
     * @var string[]
     */
    private const SENSITIVE_KEY_PATTERNS = [
        '/api[_-]?key/i',
        '/authorization/i',
        '/bearer/i',
        '/token/i',
        '/secret/i',
        '/password/i',
        '/passwd/i',
        '/cookie/i',
        '/session/i',
    ];

    /**
     * Подготавливает структуру для безопасного логирования.
     *
     * @param mixed $value Исходные данные для логирования.
     * @param int   $maxStringLength Максимальная длина строкового поля.
     * @param int   $maxDepth Максимальная глубина обхода вложенности.
     *
     * @return mixed
     */
    public static function sanitize(mixed $value, int $maxStringLength = 2000, int $maxDepth = 8): mixed
    {
        return self::sanitizeValue($value, null, $maxStringLength, $maxDepth);
    }

    /**
     * Возвращает короткий превью-текст и исходную длину.
     *
     * @param string|null $text Исходный текст.
     * @param int         $maxLength Максимальный размер превью.
     *
     * @return array{preview: string, length: int}
     */
    public static function preview(?string $text, int $maxLength = 2000): array
    {
        $text = (string) $text;

        return [
            'preview' => self::trimString($text, $maxLength),
            'length' => mb_strlen($text),
        ];
    }

    /**
     * Рекурсивная нормализация значения.
     *
     * @param mixed       $value Текущее значение.
     * @param string|null $key Текущий ключ (если известен).
     * @param int         $maxStringLength Максимальная длина строк.
     * @param int         $depth Остаточная глубина обхода.
     *
     * @return mixed
     */
    private static function sanitizeValue(mixed $value, ?string $key, int $maxStringLength, int $depth): mixed
    {
        if (self::isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if ($depth <= 0) {
            return '[DEPTH_LIMIT_REACHED]';
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $normalizedKey = is_string($childKey) ? $childKey : null;
                $result[$childKey] = self::sanitizeValue(
                    $childValue,
                    $normalizedKey,
                    $maxStringLength,
                    $depth - 1
                );
            }

            return $result;
        }

        if ($value instanceof Stringable) {
            return self::trimString((string) $value, $maxStringLength);
        }

        if (is_object($value)) {
            return sprintf('[object:%s]', $value::class);
        }

        if (in_array(get_debug_type($value), ['string'], true)) {
            return self::trimString((string) $value, $maxStringLength);
        }

        return $value;
    }

    /**
     * Проверяет, похож ли ключ на чувствительный.
     *
     * @param string|null $key Имя ключа.
     *
     * @return bool
     */
    private static function isSensitiveKey(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Обрезает строку до заданного лимита.
     *
     * @param string $value Исходная строка.
     * @param int    $maxLength Максимальная длина.
     *
     * @return string
     */
    private static function trimString(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength) . '...[truncated]';
    }
}
