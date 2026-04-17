<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\view\blocks;

use app\modules\neuron\classes\dto\tui\view\TuiBlockOptionsDto;
use app\modules\neuron\interfaces\tui\view\TuiBlockInterface;

/**
 * Блок таблицы.
 *
 * Пример использования:
 *
 * ```php
 * $table = (new TableBlockDto(['A','B'], [['1','2']]))->setCompact(true);
 * ```
 */
final class TableBlockDto implements TuiBlockInterface
{
    public const TYPE = 'table';

    /** @var list<string> */
    private array $headers;

    /** @var list<list<string>> */
    private array $rows;

    /** @var list<'left'|'right'> */
    private array $align = [];

    private bool $showHeader = true;
    private bool $compact = false;
    private TuiBlockOptionsDto $options;

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function __construct(array $headers, array $rows)
    {
        $this->headers = array_values($headers);
        $this->rows = array_values($rows);
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
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param list<string> $headers
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = array_values($headers);
        return $this;
    }

    /**
     * @return list<list<string>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @param list<list<string>> $rows
     */
    public function setRows(array $rows): self
    {
        $this->rows = array_values($rows);
        return $this;
    }

    /**
     * @return list<'left'|'right'>
     */
    public function getAlign(): array
    {
        return $this->align;
    }

    /**
     * @param list<'left'|'right'> $align
     */
    public function setAlign(array $align): self
    {
        $this->align = array_values($align);
        return $this;
    }

    public function isShowHeader(): bool
    {
        return $this->showHeader;
    }

    public function setShowHeader(bool $showHeader): self
    {
        $this->showHeader = $showHeader;
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
