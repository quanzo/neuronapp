<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\history;

use app\modules\neuron\enums\tui\TuiEntryStatusEnum;

/**
 * DTO истории TUI (Variant C).
 *
 * История — это список записей (entries). Хранение в таком виде позволяет:
 * - группировать вывод (одна команда → один entry);
 * - сворачивать/разворачивать записи;
 * - копировать/фильтровать записи;
 * - перерендеривать под разные размеры терминала.
 *
 * Пример использования:
 *
 * ```php
 * $history = (new TuiHistoryDto())
 *     ->append(TuiHistoryEntryDto::userInput('/help'))
 *     ->append(TuiHistoryEntryDto::output('OK'));
 * ```
 */
final class TuiHistoryDto
{
    /** @var list<TuiHistoryEntryDto> */
    private array $entries = [];

    /**
     * @return list<TuiHistoryEntryDto>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @param list<TuiHistoryEntryDto> $entries
     */
    public function setEntries(array $entries): self
    {
        $this->entries = array_values($entries);
        return $this;
    }

    public function append(TuiHistoryEntryDto $entry): self
    {
        $this->entries[] = $entry;
        return $this;
    }

    public function clear(): self
    {
        $this->entries = [];
        return $this;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Временная функция совместимости: преобразует историю в список строк.
     *
     * Используется до подключения форматтера блоков (widgets).
     *
     * @return list<string>
     */
    public function toPlainTextMessages(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            $txt = $entry->getPlainText();
            if ($txt === null) {
                continue;
            }
            $out[] = $txt;
        }
        return $out;
    }

    public function appendOutput(string $text, TuiEntryStatusEnum $status = TuiEntryStatusEnum::Ok): self
    {
        return $this->append(TuiHistoryEntryDto::output($text, $status));
    }

    public function appendEvent(string $text, TuiEntryStatusEnum $status = TuiEntryStatusEnum::Info): self
    {
        return $this->append(TuiHistoryEntryDto::event($text, $status));
    }
}
