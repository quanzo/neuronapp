<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui;

/**
 * DTO решения pre-hook: что выводить в TUI.
 *
 * Пример использования:
 *
 * ```php
 * $decision = new PreOutputDecisionDto($input, $input);
 * if ($decision->getOutputText() === null) { ... }
 * ```
 */
final class PreOutputDecisionDto
{
    /**
     * @param string      $originalInput Исходный введённый текст (может быть многострочным).
     * @param string|null $outputText    Текст для вывода (null означает «не выводить»).
     */
    public function __construct(
        private readonly string $originalInput,
        private readonly ?string $outputText,
    ) {
    }

    /**
     * Возвращает исходный введённый текст.
     */
    public function getOriginalInput(): string
    {
        return $this->originalInput;
    }

    /**
     * Возвращает текст для вывода (или null, если вывод отменён).
     */
    public function getOutputText(): ?string
    {
        return $this->outputText;
    }
}
