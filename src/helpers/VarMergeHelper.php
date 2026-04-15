<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\tools\VarMergeResultDto;
use app\modules\neuron\enums\VarDataTypeEnum;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function str_ends_with;
use function str_starts_with;

/**
 * VarMergeHelper — объединение (merge/pad) значений переменных разных типов.
 *
 * Хелпер реализует детерминированные правила `var_pad`, чтобы корректно дополнять
 * уже сохранённые значения без неявных конверсий типов.
 *
 * ### Правила `mergeForPad`
 * - **string**: дописывание текста с аккуратной обработкой переводов строк.
 * - **array**:
 *   - list + list → конкатенация
 *   - list + map  → добавление map как одного элемента в конец list
 *   - list + scalar/object → добавление одного элемента в конец list
 *   - map + map   → merge по ключам с overwrite (append заменяет existing)
 * - **number**: арифметическое сложение.
 * - **null**: трактуется как “пусто” → результатом становится append.
 * - **boolean/object**: не поддерживаются для pad (ошибка).
 *
 * ### Пример использования
 *
 * ```php
 * use app\modules\neuron\helpers\VarMergeHelper;
 *
 * $r = VarMergeHelper::mergeForPad(['a' => 1], ['a' => 2, 'b' => 3]);
 * // $r->merged === ['a' => 2, 'b' => 3]
 * ```
 */
final class VarMergeHelper
{
    /**
     * Выполняет typed-merge для `var_pad`.
     *
     * @param mixed $existing Текущее значение переменной (из VarStorage).
     * @param mixed $append   Добавляемое значение (после парсинга входа инструмента).
     *
     * @return VarMergeResultDto Результат merge с сообщением и итоговым значением.
     */
    public static function mergeForPad(mixed $existing, mixed $append): VarMergeResultDto
    {
        $existingType = VarDataTypeEnum::fromMixed($existing);
        $appendType   = VarDataTypeEnum::fromMixed($append);

        if ($existing === null) {
            return new VarMergeResultDto(
                success     : true,
                message     : 'OK',
                merged      : $append,
                mergedType  : $appendType->value,
                existingType: $existingType,
                appendType  : $appendType,
            );
        }

        if (is_string($existing)) {
            if (!is_string($append)) {
                return new VarMergeResultDto(
                    success     : false,
                    message     : 'Pad для string поддерживает только строковые данные. Передайте обычный текст, либо используйте var_set.',
                    merged      : null,
                    mergedType  : null,
                    existingType: $existingType,
                    appendType  : $appendType,
                );
            }

            return new VarMergeResultDto(
                success     : true,
                message     : 'OK',
                merged      : self::mergeWithNewline($existing, $append),
                mergedType  : VarDataTypeEnum::STRING->value,
                existingType: $existingType,
                appendType  : $appendType,
            );
        }

        if (is_int($existing) || is_float($existing)) {
            if (!is_int($append) && !is_float($append)) {
                return new VarMergeResultDto(
                    success     : false,
                    message     : 'Pad для number поддерживает только числовые данные (int/float). Передайте JSON-число, либо используйте var_set.',
                    merged      : null,
                    mergedType  : null,
                    existingType: $existingType,
                    appendType  : $appendType,
                );
            }

            $merged = $existing + $append;
            return new VarMergeResultDto(
                success     : true,
                message     : 'OK',
                merged      : $merged,
                mergedType  : VarDataTypeEnum::fromMixed($merged)->value,
                existingType: $existingType,
                appendType  : $appendType,
            );
        }

        if (is_array($existing)) {
            if (is_array($append)) {
                $existingIsList = array_is_list($existing);
                $appendIsList   = array_is_list($append);

                if ($existingIsList) {
                    if ($appendIsList) {
                        return new VarMergeResultDto(
                            success     : true,
                            message     : 'OK',
                            merged      : [...$existing, ...$append],
                            mergedType  : VarDataTypeEnum::ARRAY->value,
                            existingType: $existingType,
                            appendType  : $appendType,
                        );
                    }

                      // list + map: добавляем map как один элемент.
                    $merged   = $existing;
                    $merged[] = $append;
                    return new VarMergeResultDto(
                        success     : true,
                        message     : 'OK',
                        merged      : $merged,
                        mergedType  : VarDataTypeEnum::ARRAY->value,
                        existingType: $existingType,
                        appendType  : $appendType,
                    );
                }

                  // existing map: append должен быть map.
                if ($appendIsList) {
                    return new VarMergeResultDto(
                        success     : false,
                        message     : 'Pad для map-массива требует JSON-объект (ассоциативный массив). Передан list-массив.',
                        merged      : null,
                        mergedType  : null,
                        existingType: $existingType,
                        appendType  : $appendType,
                    );
                }

                $merged = $existing;
                foreach ($append as $k => $v) {
                    $merged[$k] = $v;
                }

                return new VarMergeResultDto(
                    success     : true,
                    message     : 'OK',
                    merged      : $merged,
                    mergedType  : VarDataTypeEnum::ARRAY->value,
                    existingType: $existingType,
                    appendType  : $appendType,
                );
            }

              // existing list: допускаем добавление одного элемента любого типа.
            if (array_is_list($existing)) {
                $merged   = $existing;
                $merged[] = $append;
                return new VarMergeResultDto(
                    success     : true,
                    message     : 'OK',
                    merged      : $merged,
                    mergedType  : VarDataTypeEnum::ARRAY->value,
                    existingType: $existingType,
                    appendType  : $appendType,
                );
            }

            return new VarMergeResultDto(
                success     : false,
                message     : 'Pad для map-массива требует JSON-объект (ассоциативный массив). Передан скаляр/строка.',
                merged      : null,
                mergedType  : null,
                existingType: $existingType,
                appendType  : $appendType,
            );
        }

        if (is_bool($existing)) {
            return new VarMergeResultDto(
                success     : false,
                message     : 'Pad не поддерживает boolean. Используйте var_set.',
                merged      : null,
                mergedType  : null,
                existingType: $existingType,
                appendType  : $appendType,
            );
        }

        return new VarMergeResultDto(
            success     : false,
            message     : 'Pad не поддерживает этот тип данных. Используйте var_set.',
            merged      : null,
            mergedType  : null,
            existingType: $existingType,
            appendType  : $appendType,
        );
    }

    /**
     * Аккуратно склеивает строки, сохраняя переводы строк.
     *
     * @param string $existing Текущий текст.
     * @param string $append   Добавляемый текст.
     *
     * @return string Результат объединения.
     */
    private static function mergeWithNewline(string $existing, string $append): string
    {
        if ($existing === '') {
            return $append;
        }
        if ($append === '') {
            return $existing;
        }

        $existingEndsNl = str_ends_with($existing, "\n");
        $appendStartsNl = str_starts_with($append, "\n");

        if (!$existingEndsNl && !$appendStartsNl) {
            return $existing . "\n" . $append;
        }

        if ($existingEndsNl && $appendStartsNl) {
            return $existing . ltrim($append, "\n");
        }

        return $existing . $append;
    }
}
