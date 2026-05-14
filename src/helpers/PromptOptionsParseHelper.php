<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Разбор значений опций блока Skill/TodoList при многострочном JSON.
 *
 * Стандартный построчный парсер берёт только текст после первого «:» на одной строке.
 * Если там начинается объект или массив JSON, но строка не является цельным JSON,
 * этот хелпер склеивает последующие строки блока опций до получения валидного JSON
 * или до границы следующей опции (строка вида «идентификатор: …» без кавычки в начале).
 *
 * Пример (фрагмент skill-файла):
 *
 * <code>
 * params: {
 *   "query": {"type": "string", "required": true}
 * }
 * tools: wiki_search
 * </code>
 */
final class PromptOptionsParseHelper
{
    /**
     * Максимум дополнительных строк, подклеиваемых к значению одной опции.
     */
    private const MAX_EXTRA_LINES = 256;

    /**
     * Максимальный размер буфера (байты), защита от раздувания памяти.
     */
    private const MAX_BUFFER_BYTES = 1048576;

    /**
     * Проверяет, имеет смысл пытаться подклеивать следующие строки как продолжение JSON.
     *
     * Условие: непустой trim, первый символ «{» или «[», и однострочный json_decode неуспешен.
     *
     * @param string $rawFirstFragment Текст после «name:» на первой строке опции (уже trim снаружи допускается).
     */
    public static function shouldTryMultilineJsonContinuation(string $rawFirstFragment): bool
    {
        $t = trim($rawFirstFragment);
        if ($t === '') {
            return false;
        }
        $first = $t[0];
        if ($first !== '{' && $first !== '[') {
            return false;
        }

        JsonHelper::decodeAssociative($t);

        return json_last_error() !== JSON_ERROR_NONE;
    }

    /**
     * Склеивает значение опции из нескольких строк до валидного JSON или до стоп-условия.
     *
     * @param string[] $lines            Все строки блока опций (как в {@see \app\modules\neuron\classes\APromptComponent::parseOptions()}).
     * @param int      $optionLineIndex  Индекс строки, где объявлена опция («params: {»).
     * @param string   $firstLineRawValue Текст после первого «:» на этой строке (после trim).
     *
     * @return array{0: string, 1: int} Пара: [полный текст значения, число дополнительно поглощённых строк после строки опции].
     */
    public static function accumulateMultilineJsonValue(
        array $lines,
        int $optionLineIndex,
        string $firstLineRawValue
    ): array {
        $buffer = trim($firstLineRawValue);
        $extraConsumed = 0;

        while ($extraConsumed <= self::MAX_EXTRA_LINES && strlen($buffer) <= self::MAX_BUFFER_BYTES) {
            $trimmed = trim($buffer);
            JsonHelper::decodeAssociative($trimmed);
            if (json_last_error() === JSON_ERROR_NONE) {
                return [$buffer, $extraConsumed];
            }

            $scan = self::scanJsonStructure($buffer);
            if ($scan['balanced'] && json_last_error() !== JSON_ERROR_NONE) {
                break;
            }

            if (!$scan['balanced']) {
                $nextIdx = $optionLineIndex + $extraConsumed + 1;
                if ($nextIdx >= count($lines)) {
                    break;
                }
                $nextLine = $lines[$nextIdx];
                if (!self::shouldAppendLineAsJsonContinuation($buffer, $nextLine)) {
                    break;
                }
                $buffer .= "\n" . $nextLine;
                ++$extraConsumed;
                continue;
            }

            break;
        }

        return [$buffer, $extraConsumed];
    }

    /**
     * Определяет, можно ли подклеить следующую строку как часть JSON.
     *
     * Если незакрытая строка JSON — разрешаем любую строку (в т.ч. с «name:»).
     * Иначе не подклеиваем строку, похожую на новую опцию «идентификатор: значение».
     *
     * @param string $buffer    Текущий накопленный фрагмент JSON.
     * @param string $nextLine  Следующая сырая строка из блока опций.
     */
    public static function shouldAppendLineAsJsonContinuation(string $buffer, string $nextLine): bool
    {
        $scan = self::scanJsonStructure($buffer);
        if ($scan['in_string']) {
            return true;
        }

        $t = trim($nextLine);
        if ($t === '') {
            return true;
        }

        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*:/', $t) !== 1;
    }

    /**
     * Сканирует буфер как JSON-подобный текст: скобки вне строк, незакрытая строка.
     *
     * @return array{
     *     balanced: bool,
     *     in_string: bool
     * }
     */
    private static function scanJsonStructure(string $s): array
    {
        $len = strlen($s);
        $stack = [];
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($inString) {
                if ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($c === '"') {
                $inString = true;
                continue;
            }

            if ($c === '{') {
                $stack[] = '}';
            } elseif ($c === '[') {
                $stack[] = ']';
            } elseif ($c === '}' || $c === ']') {
                if ($stack === []) {
                    return ['balanced' => false, 'in_string' => false];
                }
                $expected = array_pop($stack);
                if ($expected !== $c) {
                    return ['balanced' => false, 'in_string' => false];
                }
            }
        }

        $balanced = $stack === [] && !$inString;

        return [
            'balanced' => $balanced,
            'in_string' => $inString,
        ];
    }
}
