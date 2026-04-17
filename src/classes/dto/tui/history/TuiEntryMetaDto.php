<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\history;

use app\modules\neuron\enums\tui\TuiEntryStatusEnum;

/**
 * DTO метаданных записи истории TUI.
 *
 * Пример использования:
 *
 * ```php
 * $meta = (new TuiEntryMetaDto())
 *     ->setStatus(TuiEntryStatusEnum::Ok)
 *     ->setDurationMs(12)
 *     ->setSource('help');
 * ```
 */
final class TuiEntryMetaDto
{
    private TuiEntryStatusEnum $status = TuiEntryStatusEnum::Info;
    private ?int $durationMs = null;
    private ?string $source = null;

    /** @var list<string> */
    private array $tags = [];

    public function getStatus(): TuiEntryStatusEnum
    {
        return $this->status;
    }

    public function setStatus(TuiEntryStatusEnum $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): self
    {
        $this->durationMs = $durationMs;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->source = $source;
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param list<string> $tags
     */
    public function setTags(array $tags): self
    {
        $this->tags = array_values($tags);
        return $this;
    }
}
