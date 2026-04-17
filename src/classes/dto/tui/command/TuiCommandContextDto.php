<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\command;

use app\modules\neuron\classes\dto\tui\history\TuiHistoryDto;

/**
 * DTO контекста выполнения TUI-команды.
 *
 * Пример использования:
 *
 * ```php
 * $ctx = new TuiCommandContextDto(getcwd(), $history);
 * ```
 */
final class TuiCommandContextDto
{
    public function __construct(
        private readonly string $cwd,
        private readonly TuiHistoryDto $history,
    ) {
    }

    public function getCwd(): string
    {
        return $this->cwd;
    }

    public function getHistory(): TuiHistoryDto
    {
        return $this->history;
    }
}
