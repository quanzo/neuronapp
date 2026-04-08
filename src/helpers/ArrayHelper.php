<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Хелпер для работы с массивами.
 *
 * Содержит небольшие чистые функции для нормализации списков.
 *
 * Пример:
 * <code>
 * $names = ArrayHelper::getUniqStrList([' a ', '', 'b', 'a', null, 10]);
 * // ['a', 'b']
 * </code>
 */
final class ArrayHelper
{
    /**
     * Возвращает список уникальных непустых строк.
     *
     * - нестроковые элементы игнорируются;
     * - строки тримятся;
     * - пустые строки отбрасываются;
     * - порядок сохраняется по первому появлению;
     * - сравнение строгое, регистр учитывается.
     *
     * @param array<int, mixed> $items Входной массив значений.
     *
     * @return list<string> Уникальные строки.
     */
    public static function getUniqStrList(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $seen = [];
        $result = [];

        foreach ($items as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            if (isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $result[] = $value;
        }

        return $result;
    }
}
