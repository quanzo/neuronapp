<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\view\blocks;

use app\modules\neuron\classes\dto\tui\view\TuiBlockOptionsDto;
use app\modules\neuron\interfaces\tui\view\TuiBlockInterface;

/**
 * Блок подсказок по клавишам.
 *
 * Пример использования:
 *
 * ```php
 * $hints = new KeyHintsBlockDto(['Tab: focus', 'Enter: send']);
 * ```
 */
final class KeyHintsBlockDto implements TuiBlockInterface
{
    public const TYPE = 'key_hints';

    /** @var list<string> */
    private array $items;

    private string $separator = ' | ';
    private TuiBlockOptionsDto $options;

    /**
     * @param list<string> $items
     */
    public function __construct(array $items)
    {
        $this->items = array_values($items);
        $this->options = new TuiBlockOptionsDto();
        $this->options->setWrap(false);
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    /**
     * @return list<string>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param list<string> $items
     */
    public function setItems(array $items): self
    {
        $this->items = array_values($items);
        return $this;
    }

    public function getSeparator(): string
    {
        return $this->separator;
    }

    public function setSeparator(string $separator): self
    {
        $this->separator = $separator !== '' ? $separator : ' | ';
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
