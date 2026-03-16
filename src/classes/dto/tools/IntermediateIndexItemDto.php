<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO элемента индекса промежуточных результатов (IntermediateStorage).
 *
 * Используется для list_intermediate(): показывает метаданные сохранённого значения
 * без необходимости загружать само data.
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *   'label'     => string,
 *   'fileName'  => string,
 *   'savedAt'   => string, // ISO-8601
 *   'dataType'  => string, // string|object|array|number|boolean|null
 *   'sizeBytes' => int,
 * ]
 * ```
 */
final class IntermediateIndexItemDto
{
    /**
     * @param string $label     Метка результата.
     * @param string $fileName  Имя файла в `.store`.
     * @param string $savedAt   Время сохранения (ISO-8601).
     * @param string $dataType  Тип данных.
     * @param int    $sizeBytes Размер файла (байты).
     */
    public function __construct(
        public readonly string $label,
        public readonly string $fileName,
        public readonly string $savedAt,
        public readonly string $dataType,
        public readonly int $sizeBytes,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{label: string, fileName: string, savedAt: string, dataType: string, sizeBytes: int}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'fileName' => $this->fileName,
            'savedAt' => $this->savedAt,
            'dataType' => $this->dataType,
            'sizeBytes' => $this->sizeBytes,
        ];
    }
}
