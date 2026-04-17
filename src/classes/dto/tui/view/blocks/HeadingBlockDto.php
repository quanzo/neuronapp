<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\view\blocks;

use app\modules\neuron\classes\dto\tui\view\TuiBlockOptionsDto;
use app\modules\neuron\interfaces\tui\view\TuiBlockInterface;

/**
 * Блок заголовка.
 *
 * Пример использования:
 *
 * ```php
 * $h = (new HeadingBlockDto('Workspace'))->setUnderline(true);
 * ```
 */
final class HeadingBlockDto implements TuiBlockInterface
{
    public const TYPE = 'heading';

    private int $level = 1;
    private bool $underline = true;
    private TuiBlockOptionsDto $options;

    public function __construct(
        private readonly string $text,
    ) {
        $this->options = new TuiBlockOptionsDto();
        $this->options->setWrap(false);
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = max(1, min(2, $level));
        return $this;
    }

    public function hasUnderline(): bool
    {
        return $this->underline;
    }

    public function setUnderline(bool $underline): self
    {
        $this->underline = $underline;
        return $this;
    }

    public function getOptions(): TuiBlockOptionsDto
    {
        return $this->options;
    }

    public function setOptions(TuiBlockOptionsDto $options): self
    {
        $this->options = $options;
        return $this;
    }
}
