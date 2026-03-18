<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO результата инструмента получения размера текстового файла
 * ({@see \app\modules\neuron\tools\SizeChunckTool}).
 *
 * Предоставляет сводную информацию о числе строк и символов в файле.
 *
 * Формат сериализации (toArray):
 * [
 *     'filePath'    => string, // путь к файлу (как запрошен)
 *     'totalLines'  => int,    // количество строк
 *     'totalLength' => int,    // количество символов
 * ]
 */
final class SizeChunkResultDto
{
    /**
     * @param string $filePath    Путь к файлу
     * @param int    $totalLines  Количество строк в файле
     * @param int    $totalLength Количество символов в файле
     */
    public function __construct(
        public readonly string $filePath,
        public readonly int $totalLines,
        public readonly int $totalLength,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{filePath: string, totalLines: int, totalLength: int}
     */
    public function toArray(): array
    {
        return [
            'filePath' => $this->filePath,
            'totalLines' => $this->totalLines,
            'totalLength' => $this->totalLength
        ];
    }
}
