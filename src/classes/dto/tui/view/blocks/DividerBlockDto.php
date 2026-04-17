<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\view\blocks;

use app\modules\neuron\classes\dto\tui\view\TuiBlockOptionsDto;
use app\modules\neuron\interfaces\tui\view\TuiBlockInterface;

/**
 * Блок-разделитель (горизонтальная линия).
 *
 * Пример использования:
 *
 * ```php
 * $d = (new DividerBlockDto())->setChar('─');
 * ```
 */
final class DividerBlockDto implements TuiBlockInterface
{
    public const TYPE = 'divider';

    private string $char = '─';
    private TuiBlockOptionsDto $options;

    public function __construct()
    {
        $this->options = new TuiBlockOptionsDto();
        $this->options->setWrap(false);
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getChar(): string
    {
        return $this->char;
    }

    public function setChar(string $char): self
    {
        $this->char = $char !== '' ? $char : '─';
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
