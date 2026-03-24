<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO результата работы инструмента SearchReplaceTool.
 *
 * Описывает, какие файлы и сколько раз были (или могли быть) изменены
 * при выполнении поиска и замены, а также режим работы (dry-run или реальные правки).
 *
 * Формат сериализации (toArray):
 * [
 *     'pattern'      => string,                     // исходный паттерн поиска
 *     'replacement'  => string,                     // строка-замена
 *     'dryRun'       => bool,                       // true — только план без изменений
 *     'changes'      => array<string,int>,          // filePath => count замен
 *     'totalChanges' => int,                        // общее количество замен
 *     'errors'       => string[],                   // сообщения об ошибках по файлам
 * ]
 */
final class SearchReplaceResultDto implements IArrayable
{
    /**
     * @param string              $pattern      Паттерн для поиска
     * @param string              $replacement  Строка-замена
     * @param bool                $dryRun       Флаг режима dry-run
     * @param array<string,int>   $changes      Карта файлов и количества замен
     * @param int                 $totalChanges Общее количество замен
     * @param string[]            $errors       Список сообщений об ошибках
     */
    public function __construct(
        public readonly string $pattern,
        public readonly string $replacement,
        public readonly bool $dryRun,
        public readonly array $changes,
        public readonly int $totalChanges,
        public readonly array $errors,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{
     *     pattern: string,
     *     replacement: string,
     *     dryRun: bool,
     *     changes: array<string,int>,
     *     totalChanges: int,
     *     errors: string[]
     * }
     */
    public function toArray(): array
    {
        return [
            'pattern' => $this->pattern,
            'replacement' => $this->replacement,
            'dryRun' => $this->dryRun,
            'changes' => $this->changes,
            'totalChanges' => $this->totalChanges,
            'errors' => $this->errors,
        ];
    }
}
