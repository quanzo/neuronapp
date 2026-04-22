<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\dto;

/**
 * DTO результата полной обработки выходного текста LLM.
 */
class OutputSafeResultDto
{
    /**
     * Финальный безопасный текст.
     */
    private string $safeText = '';

    /**
     * @var list<OutputViolationDto>
     */
    private array $violations = [];

    /**
     * Возвращает итоговый безопасный текст.
     */
    public function getSafeText(): string
    {
        return $this->safeText;
    }

    /**
     * Устанавливает итоговый безопасный текст.
     */
    public function setSafeText(string $safeText): self
    {
        $this->safeText = $safeText;
        return $this;
    }

    /**
     * Добавляет информацию о найденном нарушении.
     */
    public function addViolation(OutputViolationDto $violation): self
    {
        $this->violations[] = $violation;
        return $this;
    }

    /**
     * Возвращает список нарушений.
     *
     * @return list<OutputViolationDto>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * Возвращает true, если были редактирования/нарушения.
     */
    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    /**
     * Возвращает DTO в массиве для логирования.
     *
     * @return array{safeText:string,violations:list<array{code:string,reason:string,matchedFragment:string,replacement:string}>}
     */
    public function toArray(): array
    {
        return [
            'safeText'    => $this->safeText,
            'violations'  => array_map(
                static fn (OutputViolationDto $violation): array => $violation->toArray(),
                $this->violations
            ),
        ];
    }
}
