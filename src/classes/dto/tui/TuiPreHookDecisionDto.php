<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui;

use app\modules\neuron\classes\dto\tui\history\TuiHistoryEntryDto;

/**
 * DTO решения pre-hook для интерактивного TUI.
 *
 * Позволяет управлять rich-историей (Variant C): добавить entries/blocks,
 * очистить историю и/или завершить TUI-цикл.
 *
 * Пример использования:
 *
 * ```php
 * $decision = (new TuiPreHookDecisionDto($input))
 *     ->setAppendEntries([$entry])
 *     ->setClearHistory(false)
 *     ->setExit(false);
 * ```
 */
final class TuiPreHookDecisionDto
{
    /** @var list<TuiHistoryEntryDto> */
    private array $appendEntries = [];

    private bool $clearHistory = false;
    private bool $exit = false;

    public function __construct(
        private readonly string $originalInput,
    ) {
    }

    public function getOriginalInput(): string
    {
        return $this->originalInput;
    }

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
