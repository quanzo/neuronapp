<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\view;

/**
 * DTO опций рендера блока.
 *
 * Пример использования:
 *
 * ```php
 * $opt = (new TuiBlockOptionsDto())->setIndent(2)->setWrap(true);
 * ```
 */
final class TuiBlockOptionsDto
{
    private int $indent = 0;
    private bool $wrap = true;
    private bool $compact = false;

    public function getIndent(): int
    {
        return $this->indent;
    }

    public function setIndent(int $indent): self
    {
        $this->indent = max(0, $indent);
        return $this;
    }

    public function isWrap(): bool
    {
        return $this->wrap;
    }

    public function setWrap(bool $wrap): self
    {
        $this->wrap = $wrap;
        return $this;
    }

    public function isCompact(): bool
    {
        return $this->compact;
    }

    public function setCompact(bool $compact): self
    {
        $this->compact = $compact;
        return $this;
    }
}
