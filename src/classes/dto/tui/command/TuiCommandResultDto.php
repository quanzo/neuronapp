<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\command;

use app\modules\neuron\classes\dto\tui\history\TuiHistoryEntryDto;

/**
 * DTO результата выполнения TUI-команды.
 *
 * Пример использования:
 *
 * ```php
 * return (new TuiCommandResultDto())->setAppendEntries([$entry]);
 * ```
 */
final class TuiCommandResultDto
{
    /** @var list<TuiHistoryEntryDto> */
    private array $appendEntries = [];

    private bool $clearHistory = false;
    private bool $exit = false;

    /**
     * @return list<TuiHistoryEntryDto>
     */
    public function getAppendEntries(): array
    {
        return $this->appendEntries;
    }

    /**
     * @param list<TuiHistoryEntryDto> $appendEntries
     */
    public function setAppendEntries(array $appendEntries): self
    {
        $this->appendEntries = array_values($appendEntries);
        return $this;
    }

    public function isClearHistory(): bool
    {
        return $this->clearHistory;
    }

    public function setClearHistory(bool $clearHistory): self
    {
        $this->clearHistory = $clearHistory;
        return $this;
    }

    public function isExit(): bool
    {
        return $this->exit;
    }

    public function setExit(bool $exit): self
    {
        $this->exit = $exit;
        return $this;
    }
}
