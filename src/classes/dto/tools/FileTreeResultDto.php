<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO результата работы инструмента FileTreeTool.
 *
 * Представляет плоский список узлов файловой системы с минимальными метаданными,
 * пригодными для восстановления дерева или фильтрации в LLM.
 *
 * Каждый узел описывается структурой:
 * [
 *     'path'  => string,          // путь относительно базовой директории
 *     'type'  => 'file'|'dir',    // тип узла
 *     'depth' => int,             // глубина относительно корня (0 — корень)
 * ]
 *
 * Формат сериализации (toArray):
 * [
 *     'basePath'   => string,
 *     'nodes'      => array<int,array{path:string,type:string,depth:int}>,
 *     'truncated'  => bool,
 *     'totalNodes' => int,
 * ]
 */
final class FileTreeResultDto
{
    /**
     * @param string $basePath   Базовая директория обхода
     * @param array<int,array{path:string,type:string,depth:int}> $nodes Список узлов
     * @param bool   $truncated  Был ли список усечён по лимиту
     * @param int    $totalNodes Общее количество найденных узлов
     */
    public function __construct(
        public readonly string $basePath,
        public readonly array $nodes,
        public readonly bool $truncated,
        public readonly int $totalNodes,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{
     *     basePath: string,
     *     nodes: array<int,array{path:string,type:string,depth:int}>,
     *     truncated: bool,
     *     totalNodes: int
     * }
     */
    public function toArray(): array
    {
        return [
            'basePath' => $this->basePath,
            'nodes' => $this->nodes,
            'truncated' => $this->truncated,
            'totalNodes' => $this->totalNodes,
        ];
    }
}

