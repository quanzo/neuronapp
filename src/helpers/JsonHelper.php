<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use RuntimeException;

use const JSON_ERROR_NONE;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Единая точка для JSON в проекте: флаги кодирования/декодирования и типовые сценарии.
 *
 * Политика:
 * - для вывода в логи, инструменты и файлы по умолчанию используется {@see JSON_UNESCAPED_UNICODE};
 * - для записи критичных структур — {@see JSON_THROW_ON_ERROR};
 * - при невозможности строго закодировать UTF-8 (как в {@see \app\modules\neuron\classes\storage\VarStorage}) —
 *   {@see self::encodeUnicodeWithUtf8Fallback()}.
 *
 * Пример:
 *
 * <code>
 * $json = JsonHelper::encodeThrow(['ok' => true]);
 * $data = JsonHelper::tryDecodeAssociativeArray($json);
 * </code>
 */
final class JsonHelper
{
    /**
     * Человекочитаемый UTF-8 без \\uXXXX для кириллицы (логи, ответы инструментов).
     */
    public const FLAGS_UNICODE = JSON_UNESCAPED_UNICODE;

    /**
     * Unicode + исключение при ошибке сериализации (чекпоинты, индексы, строгие ответы).
     */
    public const FLAGS_UNICODE_THROW = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Unicode + подстановка некорректных UTF-8 последовательностей (fallback после неудачного строгого encode).
     */
    public const FLAGS_UNICODE_LENIENT = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Unicode + форматирование для файлов истории чата и отладки.
     */
    public const FLAGS_UNICODE_PRETTY = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;

    /**
     * Unicode + pretty print + исключение при ошибке (сохранение истории в файл).
     */
    public const FLAGS_UNICODE_PRETTY_THROW = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR;

    /**
     * Глубина вложенности по умолчанию (как в PHP для json_decode с флагами).
     */
    public const DEFAULT_MAX_DEPTH = 512;

    /**
     * Обёрка над json_encode с флагами проекта.
     *
     * @param mixed $value Кодируемое значение.
     * @param int   $flags Битовая маска JSON_* (по умолчанию только Unicode).
     *
     * @return string|false Как в json_encode.
     */
    public static function encode(mixed $value, int $flags = self::FLAGS_UNICODE): string|false
    {
        return json_encode($value, $flags, self::DEFAULT_MAX_DEPTH);
    }

    /**
     * Кодирует значение с {@see self::FLAGS_UNICODE_THROW}.
     *
     * @param mixed $value Кодируемое значение.
     *
     * @return string JSON-строка.
     *
     * @throws \JsonException При ошибке кодирования.
     */
    public static function encodeThrow(mixed $value): string
    {
        return static::encode($value, self::FLAGS_UNICODE_THROW);
    }

    /**
     * Кодирует с Unicode, переносами строк и {@see JSON_THROW_ON_ERROR} (файл полной истории чата).
     *
     * @param mixed $value Кодируемое значение.
     *
     * @return string JSON-строка.
     *
     * @throws \JsonException При ошибке кодирования.
     */
    public static function encodeUnicodePrettyThrow(mixed $value): string
    {
        return static::encode($value, self::FLAGS_UNICODE_PRETTY_THROW);
    }

    /**
     * Сначала строгое кодирование; при любой ошибке — с {@see JSON_INVALID_UTF8_SUBSTITUTE} (см. VarStorage).
     *
     * @param mixed $value Кодируемое значение.
     *
     * @return string JSON-строка (всегда успешная запись после fallback).
     */
    public static function encodeUnicodeWithUtf8Fallback(mixed $value): string
    {
        try {
            return static::encode($value, self::FLAGS_UNICODE_THROW);
        } catch (\Throwable) {
            return static::encode($value, self::FLAGS_UNICODE_LENIENT);
        }
    }

    /**
     * Обёрка над json_decode в ассоциативный массив (второй аргумент true).
     *
     * @param string $json JSON-строка.
     *
     * @return mixed Результат декодирования; при ошибке — null (как json_decode).
     */
    public static function decodeAssociative(string $json): mixed
    {
        return json_decode($json, true, self::DEFAULT_MAX_DEPTH, 0);
    }

    /**
     * Декодирует JSON с {@see JSON_THROW_ON_ERROR} и ассоциативными массивами.
     *
     * @param string $json   JSON-строка.
     * @param int    $depth  Максимальная глубина вложенности.
     *
     * @return mixed Массив или скаляры по содержимому JSON.
     *
     * @throws \JsonException При синтаксической ошибке или превышении глубины.
     */
    public static function decodeAssociativeThrow(string $json, int $depth = self::DEFAULT_MAX_DEPTH): mixed
    {
        return json_decode($json, true, $depth, JSON_THROW_ON_ERROR);
    }

    /**
     * Эквивалент {@code json_decode($raw, true) ?? []}: для невалидного JSON или null — пустой массив.
     *
     * @param string $json JSON-строка.
     *
     * @return array<mixed>
     */
    public static function decodeAssociativeOrEmpty(string $json): array
    {
        $decoded = json_decode($json, true, self::DEFAULT_MAX_DEPTH);

        return $decoded ?? [];
    }

    /**
     * Возвращает ассоциативный массив только если JSON валиден и результат — array (например чтение .store / чекпоинта).
     *
     * @param string $json JSON-строка.
     *
     * @return array<mixed>|null null при ошибке парсинга или если декодированное значение не массив.
     */
    public static function tryDecodeAssociativeArray(string $json): ?array
    {
        $decoded = json_decode($json, true, self::DEFAULT_MAX_DEPTH);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Загрузка конфигурации из JSON/JSONC уже без комментариев: валидный JSON и корневой объект — array.
     *
     * @param string $json     Очищенная JSON-строка.
     * @param string $filePath Путь к файлу (для текста исключений).
     *
     * @return array<mixed>
     *
     * @throws RuntimeException Если JSON невалиден или корень не объект-массив.
     */
    public static function decodeAssociativeForConfigFile(string $json, string $filePath): array
    {
        $decoded = json_decode($json, true, self::DEFAULT_MAX_DEPTH);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(sprintf(
                'Invalid JSONC in configuration file %s: %s',
                $filePath,
                json_last_error_msg()
            ));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'Configuration file %s must decode to an associative array.',
                $filePath
            ));
        }

        return $decoded;
    }
}
