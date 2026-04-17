<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\view\blocks;

use app\modules\neuron\classes\dto\tui\view\TuiBlockOptionsDto;
use app\modules\neuron\interfaces\tui\view\TuiBlockInterface;

/**
 * Блок списка.
 *
 * Пример использования:
 *
 * ```php
 * $list = (new ListBlockDto(['a', 'b']))->setBullet('•');
 * ```
 */
final class ListBlockDto implements TuiBlockInterface
{
    public const TYPE = 'list';

    /** @var list<string> */
    private array $items;

    private string $bullet = '•';
    private TuiBlockOptionsDto $options;

    /**
     * @param list<string> $items
     */
    public function __construct(array $items)
    {
        $this->items = array_values($items);
        $this->options = new TuiBlockOptionsDto();
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

    public function getBullet(): string
    {
        return $this->bullet;
    }

    public function setBullet(string $bullet): self
    {
        $this->bullet = $bullet !== '' ? $bullet : '•';
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
