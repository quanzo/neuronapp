<?php

declare(strict_types=1);

namespace app\modules\neuron\enums;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Тип данных переменной в `.store` (поле `dataType`).
 *
 * Значения перечисления соответствуют строкам, которые сохраняет `VarStorage`
 * (`string|object|array|number|boolean|null`).
 *
 * ### Пример использования
 *
 * ```php
 * use app\modules\neuron\enums\VarDataTypeEnum;
 *
 * $type = VarDataTypeEnum::fromMixed(['a' => 1]); // VarDataTypeEnum::ARRAY
 * $s = $type->value; // 'array'
 * ```
 */
enum VarDataTypeEnum: string
{
    case STRING = 'string';
    case OBJECT = 'object';
    case ARRAY = 'array';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case NULL = 'null';

    /**
     * Определяет тип данных в терминах `.store` по значению.
     *
     * @param mixed $data Значение.
     * @return self Тип данных.
     */
    public static function fromMixed(mixed $data): self
    {
        if ($data === null) {
            return self::NULL;
        }
        if (is_string($data)) {
            return self::STRING;
        }
        if (is_int($data) || is_float($data)) {
            return self::NUMBER;
        }
        if (is_bool($data)) {
            return self::BOOLEAN;
        }
        if (is_array($data)) {
            return self::ARRAY;
        }
        return self::OBJECT;
    }
}
