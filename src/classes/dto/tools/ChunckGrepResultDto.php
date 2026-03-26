<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

use function array_map;

/**
 * DTO результата поиска по тексту файла с возвратом семантических чанков.
 *
 * Используется инструментом {@see \app\modules\neuron\tools\ChunckGrepTool}.
 *
 * Формат сериализации (toArray):
 * [
 *   'filePath'          => string,
 *   'query'             => string, // строка или regex (как передано)
 *   'maxTotalChars'     => int,
 *   'maxCharsPerBlock'  => int,
 *   'chunks'            => array<int, array>, // MarkdownChunkDto::toArray()
 *   'totalChunks'       => int,
 *   'totalChars'        => int,
 * ]
 */
final class ChunckGrepResultDto implements IArrayable
{
    /**
     * @param string           $filePath Путь к файлу (как запрошен).
     * @param string           $query Строка поиска (regex или обычный текст).
     * @param int              $maxTotalChars Лимит суммарного объёма возвращаемого контента.
     * @param int              $maxCharsPerBlock Лимит на один чанк.
     * @param MarkdownChunkDto[] $chunks Список найденных чанков.
     */
    public function __construct(
        public readonly string $filePath,
        public readonly string $query,
        public readonly int $maxTotalChars,
        public readonly int $maxCharsPerBlock,
        public readonly array $chunks,
    ) {
    }

    /**
     * @return int
     */
    public function getTotalChunks(): int
    {
        return count($this->chunks);
    }

    /**
     * @return int
     */
    public function getTotalChars(): int
    {
        $sum = 0;
        foreach ($this->chunks as $chunk) {
            $sum += $chunk->lengthChars;
        }
        return $sum;
    }

    /**
     * @return array{
     *   filePath:string,
     *   query:string,
     *   maxTotalChars:int,
     *   maxCharsPerBlock:int,
     *   chunks:array<int, array>,
     *   totalChunks:int,
     *   totalChars:int
     * }
     */
    public function toArray(): array
    {
        return [
            'filePath' => $this->filePath,
            'query' => $this->query,
            'maxTotalChars' => $this->maxTotalChars,
            'maxCharsPerBlock' => $this->maxCharsPerBlock,
            'chunks' => array_map(
                static fn(MarkdownChunkDto $c): array => $c->toArray(),
                $this->chunks,
            ),
            'totalChunks' => $this->getTotalChunks(),
            'totalChars' => $this->getTotalChars(),
        ];
    }
}
