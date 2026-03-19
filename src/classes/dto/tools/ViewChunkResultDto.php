<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO результата чтения текстового файла чанком строк ({@see \app\modules\neuron\tools\ChunckViewTool}).
 *
 * Используется, чтобы возвращать LLM не весь файл целиком, а компактный
 * непрерывный фрагмент (чанк), ограниченный количеством строк и/или
 * максимальным размером в символах.
 *
 * Формат сериализации (toArray):
 * [
 *     'filePath'     => string, // путь к файлу (как запрошен)
 *     'chunk'        => string, // содержимое чанка, строки разделены "\n"
 *     'startLine'    => int,    // номер первой строки чанка (0-based)
 *     'endLine'      => int,    // номер последней строки чанка (0-based)
 *     'chunkLength'  => int,    // длина чанка в символах
 *     'totalLines'   => int,    // общее число строк в файле
 *     'totalLength'  => int,    // полное количество символов в файле
 * ]
 */
final class ViewChunkResultDto
{
    /**
     * @param string $filePath    Путь к файлу
     * @param string $chunk       Содержимое чанка (несколько строк)
     * @param int    $startLine   Номер первой строки чанка (0-based)
     * @param int    $endLine     Номер последней строки чанка (0-based)
     * @param int    $chunkLength Количество символов в чанке
     * @param int    $totalLines  Общее количество строк в файле
     * @param int    $totalLength Общее количество символов в файле
     */
    public function __construct(
        public readonly string $filePath,
        public readonly string $chunk,
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly int $chunkLength,
        public readonly int $totalLines,
        public readonly int $totalLength,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{
     *     filePath: string,
     *     chunk: string,
     *     startLine: int,
     *     endLine: int,
     *     chunkLength: int,
     *     totalLines: int,
     *     totalLength: int
     * }
     */
    public function toArray(): array
    {
        return [
            'filePath'    => $this->filePath,
            'chunk'       => $this->chunk,
            'startLine'   => $this->startLine,
            'endLine'     => $this->endLine,
            'chunkLength' => $this->chunkLength,
            'totalLines'  => $this->totalLines,
            'totalLength' => $this->totalLength
        ];
    }
}
