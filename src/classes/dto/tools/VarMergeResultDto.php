<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\enums\VarDataTypeEnum;

/**
 * DTO результата слияния значений переменных для `var_pad`.
 *
 * DTO используется как внутренняя структурированная форма результата merge-операции,
 * чтобы `VarPadTool` мог вернуть единообразную ошибку/успех, не передавая по коду
 * «структуры в виде массивов».
 *
 * ### Пример использования
 *
 * ```php
 * use app\modules\neuron\classes\dto\tools\VarMergeResultDto;
 *
 * $r = new VarMergeResultDto(
 *     success: true,
 *     message: 'OK',
 *     merged: ['a' => 1],
 *     mergedType: 'array',
 *     existingType: VarDataTypeEnum::ARRAY,
 *     appendType: VarDataTypeEnum::ARRAY,
 * );
 * if ($r->success) {
 *     // сохранить $r->merged
 * }
 * ```
 */
final class VarMergeResultDto
{
    /**
     * @param bool        $success      Успешность операции merge.
     * @param string      $message      Сообщение (для логов/ответа инструмента).
     * @param mixed|null  $merged       Итоговое значение (только при success=true).
     * @param string|null $mergedType   Тип итогового значения (`string|array|number|boolean|null|object`).
     * @param VarDataTypeEnum $existingType Тип существующего значения.
     * @param VarDataTypeEnum $appendType   Тип добавляемого значения.
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly mixed $merged = null,
        public readonly ?string $mergedType = null,
        public readonly VarDataTypeEnum $existingType = VarDataTypeEnum::NULL,
        public readonly VarDataTypeEnum $appendType = VarDataTypeEnum::NULL,
    ) {
    }
}
