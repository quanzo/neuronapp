<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO сводки состояния git-репозитория для инструмента GitSummaryTool.
 *
 * Инкапсулирует основные текстовые блоки вывода git-команд:
 * - краткий статус (ветка и изменённые файлы),
 * - статистику diff,
 * - список последних коммитов (опционально),
 * а также путь к рабочей директории, где выполнялся анализ.
 *
 * Формат сериализации (toArray):
 * [
 *     'workingDir'   => string,
 *     'statusShort'  => string, // вывод git status --short --branch
 *     'diffStat'     => string, // вывод git diff --stat (может быть пустым)
 *     'log'          => string, // вывод git log --oneline -N (может быть пустым)
 * ]
 */
final class GitSummaryResultDto implements IArrayable
{
    /**
     * @param string $workingDir  Рабочая директория репозитория
     * @param string $statusShort Вывод git status --short --branch
     * @param string $diffStat    Вывод git diff --stat (или пустая строка)
     * @param string $log         Вывод git log --oneline -N (или пустая строка)
     */
    public function __construct(
        public readonly string $workingDir,
        public readonly string $statusShort,
        public readonly string $diffStat,
        public readonly string $log,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{
     *     workingDir: string,
     *     statusShort: string,
     *     diffStat: string,
     *     log: string
     * }
     */
    public function toArray(): array
    {
        return [
            'workingDir' => $this->workingDir,
            'statusShort' => $this->statusShort,
            'diffStat' => $this->diffStat,
            'log' => $this->log,
        ];
    }
}
