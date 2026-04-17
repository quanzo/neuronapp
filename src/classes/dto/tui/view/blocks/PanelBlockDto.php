<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\view\blocks;

use app\modules\neuron\classes\dto\tui\view\TuiBlockOptionsDto;
use app\modules\neuron\interfaces\tui\view\TuiBlockInterface;

/**
 * Блок панели (заголовок + вложенные блоки).
 *
 * Пример использования:
 *
 * ```php
 * $panel = (new PanelBlockDto('Help', [new TextBlockDto('...')]))->setBorder(true);
 * ```
 */
final class PanelBlockDto implements TuiBlockInterface
{
    public const TYPE = 'panel';

    /** @var list<TuiBlockInterface> */
    private array $body;

    private bool $border = true;
    private TuiBlockOptionsDto $options;

    /**
     * @param string $title
     * @param list<TuiBlockInterface> $body
     */
    public function __construct(
        private readonly string $title,
        array $body,
    ) {
        $this->body = array_values($body);
        $this->options = new TuiBlockOptionsDto();
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return list<TuiBlockInterface>
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * @param list<TuiBlockInterface> $body
     */
    public function setBody(array $body): self
    {
        $this->body = array_values($body);
        return $this;
    }

    public function hasBorder(): bool
    {
        return $this->border;
    }

    public function setBorder(bool $border): self
    {
        $this->border = $border;
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
