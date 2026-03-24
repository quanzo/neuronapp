<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO результата редактирования файла ({@see \app\modules\neuron\tools\EditTool}).
 *
 * Содержит статус операции (успех/ошибка), количество выполненных замен
 * и человекочитаемое описание результата. При создании нового файла
 * replacements = 0, success = true. При ошибке (множественные вхождения,
 * отсутствие файла, path-traversal) success = false с пояснением в message.
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *     'filePath'     => string,  // путь к файлу (как запрошен)
 *     'success'      => bool,    // успешность операции
 *     'replacements' => int,     // количество выполненных замен (0 или 1)
 *     'message'      => string,  // описание результата или ошибки
 * ]
 * ```
 */
final class EditResultDto implements IArrayable
{
    /**
     * @param string $filePath     Путь к файлу
     * @param bool   $success      Успешность операции
     * @param int    $replacements Количество выполненных замен
     * @param string $message      Описание результата или ошибки
     */
    public function __construct(
        public readonly string $filePath,
        public readonly bool $success,
        public readonly int $replacements,
        public readonly string $message,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{filePath: string, success: bool, replacements: int, message: string}
     */
    public function toArray(): array
    {
        return [
            'filePath' => $this->filePath,
            'success' => $this->success,
            'replacements' => $this->replacements,
            'message' => $this->message,
        ];
    }
}
