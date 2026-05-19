<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Вспомогательные методы для преобразования значений опций компонентов и скалярных литералов.
 *
 * Используется при разборе YAML-опций (think/thinking и др.), а также при парсинге
 * аргументов команд с префиксом "@@" ({@see \app\modules\neuron\classes\dto\cmd\CmdDto}).
 *
 * Пример:
 * ```php
 * $enabled = OptionsHelper::toBool($options['think'] ?? null);
 * $arg = OptionsHelper::parseScalar('"hello"');
 * $literal = OptionsHelper::formatScalar('hello');
 * ```
 */
class OptionsHelper
{
    /**
     * Преобразует значение опции в boolean.
     *
     * true: 1, true, строки '1' или 'true'.
     * false: null, 0, false, строки '0' или 'false', а также любое иное значение.
     *
     * @param mixed $option Значение опции (из getOptions() или getOption).
     *
     * @return bool
     */
    public static function toBool(mixed $option): bool
    {
        if ($option === 1 || $option === true || $option === '1' || $option === 'true') {
            return true;
        }
        if ($option === 0 || $option === false || $option === '0' || $option === 'false') {
            return false;
        }

        return false;
    }

    /**
     * Разбирает строковое представление скалярного литерала.
     *
     * Поддерживаемые значения:
     *  - строки в одинарных или двойных кавычках (с экранированием "\\");
     *  - целые и вещественные числа;
     *  - литералы true, false, null (без учёта регистра).
     * Всё остальное возвращается как строка без изменений.
     *
     * @param string $value Строковое представление значения (фрагмент после trim).
     *
     * @return mixed Распознанное скалярное значение (string|int|float|bool|null).
     */
    public static function parseScalar(string $value): mixed
    {
        $length = strlen($value);

        if (
            $length >= 2
            && (
                ($value[0] === '"' && $value[$length - 1] === '"')
                || ($value[0] === "'" && $value[$length - 1] === "'")
            )
        ) {
            $quote = $value[0];
            $inner = substr($value, 1, -1);

            return self::unescapeString($inner, $quote);
        }

        $lower = strtolower($value);

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if ($lower === 'null') {
            return null;
        }

        if (is_numeric($value)) {
            if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
                return (int) $value;
            }

            return (float) $value;
        }

        return $value;
    }

    /**
     * Снимает экранирование во внутренней части строкового литерала.
     *
     * Поддерживается базовый набор:
     *  - `\\` → `\`;
     *  - `\"` / `\'` → кавычка соответствующего типа.
     *
     * @param string $value Внутреннее содержимое строки без внешних кавычек.
     * @param string $quote Кавычка, использованная для строки (`"` или `'`).
     *
     * @return string Строка с применённым снятием экранирования.
     */
    public static function unescapeString(string $value, string $quote): string
    {
        $result = '';
        $length = strlen($value);
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $ch = $value[$i];

            if ($escaped) {
                if ($ch === '\\' || $ch === $quote) {
                    $result .= $ch;
                } else {
                    $result .= '\\' . $ch;
                }
                $escaped = false;
                continue;
            }

            if ($ch === '\\') {
                $escaped = true;
                continue;
            }

            $result .= $ch;
        }

        if ($escaped) {
            $result .= '\\';
        }

        return $result;
    }

    /**
     * Форматирует скалярное значение в строковый литерал для сигнатуры команды.
     *
     * Обратная операция к {@see OptionsHelper::parseScalar()}: из скаляра строит
     * каноническое представление (null, true/false, числа, строки в двойных кавычках
     * с экранированием `\` и `"`).
     *
     * @param mixed $value Скалярное значение (string|int|float|bool|null).
     *
     * @return string Строковое представление значения.
     */
    public static function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $escaped = '';
        $length  = strlen((string) $value);

        for ($i = 0; $i < $length; $i++) {
            $ch = $value[$i];
            if ($ch === '\\' || $ch === '"') {
                $escaped .= '\\' . $ch;
            } else {
                $escaped .= $ch;
            }
        }

        return '"' . $escaped . '"';
    }
}
