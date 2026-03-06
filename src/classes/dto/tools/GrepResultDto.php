<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO результата работы инструмента поиска текста в файлах ({@see \app\modules\neuron\tools\GrepTool}).
 *
 * Агрегирует массив совпадений ({@see GrepMatchDto}) и метаинформацию о процессе
 * поиска: общее число совпадений (включая обрезанные), количество просканированных
 * файлов, флаг усечения. Если число совпадений превышает лимит maxMatches,
 * truncated = true, а totalMatches отражает фактическое число найденных вхождений.
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *     'pattern'       => string,  // исходный паттерн поиска
 *     'matches'       => array,   // массив GrepMatchDto::toArray()
 *     'truncated'     => bool,    // были ли результаты обрезаны
 *     'totalMatches'  => int,     // полное число совпадений
 *     'filesSearched' => int,     // число просканированных файлов
 * ]
 * ```
 */
final class GrepResultDto
{
    /**
     * @param string         $pattern       Паттерн (regex или текст), по которому выполнялся поиск
     * @param GrepMatchDto[] $matches       Массив найденных совпадений
     * @param bool           $truncated     Были ли результаты усечены из-за лимита
     * @param int            $totalMatches  Общее количество совпадений до усечения
     * @param int            $filesSearched Количество просканированных файлов
     */
    public function __construct(
        public readonly string $pattern,
        public readonly array $matches,
        public readonly bool $truncated,
        public readonly int $totalMatches,
        public readonly int $filesSearched,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{pattern: string, matches: array, truncated: bool, totalMatches: int, filesSearched: int}
     */
    public function toArray(): array
    {
        return [
            'pattern' => $this->pattern,
            'matches' => array_map(
                static fn(GrepMatchDto $m): array => $m->toArray(),
                $this->matches,
            ),
            'truncated' => $this->truncated,
            'totalMatches' => $this->totalMatches,
            'filesSearched' => $this->filesSearched,
        ];
    }
}
