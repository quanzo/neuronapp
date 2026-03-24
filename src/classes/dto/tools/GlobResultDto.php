<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO результата работы инструмента поиска файлов по glob-шаблону ({@see \app\modules\neuron\tools\GlobTool}).
 *
 * Содержит список найденных файлов (относительные пути от basePath), информацию
 * о применённом паттерне и метаданные об усечении результатов. Если количество
 * найденных файлов превышает лимит maxResults, поле truncated = true,
 * а totalFound отражает полное число совпадений до обрезки.
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *     'pattern'    => string,     // исходный glob-шаблон
 *     'basePath'   => string,     // корневая директория поиска
 *     'files'      => string[],   // относительные пути найденных файлов
 *     'truncated'  => bool,       // был ли список обрезан
 *     'totalFound' => int,        // полное число совпадений
 * ]
 * ```
 */
final class GlobResultDto implements IArrayable
{
    /**
     * @param string   $pattern    Glob-шаблон, по которому выполнялся поиск
     * @param string   $basePath   Базовая директория поиска
     * @param string[] $files      Массив относительных путей найденных файлов
     * @param bool     $truncated  Были ли результаты усечены из-за лимита
     * @param int      $totalFound Общее количество найденных файлов до усечения
     */
    public function __construct(
        public readonly string $pattern,
        public readonly string $basePath,
        public readonly array $files,
        public readonly bool $truncated,
        public readonly int $totalFound,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{pattern: string, basePath: string, files: string[], truncated: bool, totalFound: int}
     */
    public function toArray(): array
    {
        return [
            'pattern'    => $this->pattern,
            'basePath'   => $this->basePath,
            'files'      => $this->files,
            'truncated'  => $this->truncated,
            'totalFound' => $this->totalFound,
        ];
    }
}
