<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\dto;

/**
 * DTO результата одного правила проверки выходного текста.
 */
class OutputRuleResultDto
{
    /**
     * Текст после применения правила.
     */
    private string $text = '';

    /**
     * Признак изменения текста правилом.
     */
    private bool $changed = false;

    /**
     * Нарушение безопасности, обнаруженное правилом.
     */
    private ?OutputViolationDto $violation = null;

    /**
     * Возвращает текст после применения правила.
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Устанавливает текст после применения правила.
     */
    public function setText(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    /**
     * Возвращает признак изменения текста.
     */
    public function isChanged(): bool
    {
        return $this->changed;
    }

    /**
     * Устанавливает признак изменения текста.
     */
    public function setChanged(bool $changed): self
    {
        $this->changed = $changed;
        return $this;
    }

    /**
     * Возвращает DTO нарушения или null.
     */
    public function getViolation(): ?OutputViolationDto
    {
        return $this->violation;
    }

    /**
     * Устанавливает DTO нарушения.
     */
    public function setViolation(?OutputViolationDto $violation): self
    {
        $this->violation = $violation;
        return $this;
    }
}
