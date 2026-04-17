<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\view\blocks;

use app\modules\neuron\classes\dto\tui\view\TuiBlockOptionsDto;
use app\modules\neuron\interfaces\tui\view\TuiBlockInterface;

/**
 * Блок кода.
 *
 * Пример использования:
 *
 * ```php
 * $code = (new CodeBlockDto(\"echo 'ok';\"))->setLanguage('php');
 * ```
 */
final class CodeBlockDto implements TuiBlockInterface
{
    public const TYPE = 'code';

    private ?string $language = null;
    private bool $lineNumbers = false;
    private TuiBlockOptionsDto $options;

    public function __construct(
        private readonly string $code,
    ) {
        $this->options = new TuiBlockOptionsDto();
        $this->options->setWrap(false);
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language !== '' ? $language : null;
        return $this;
    }

    public function hasLineNumbers(): bool
    {
        return $this->lineNumbers;
    }

    public function setLineNumbers(bool $lineNumbers): self
    {
        $this->lineNumbers = $lineNumbers;
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
