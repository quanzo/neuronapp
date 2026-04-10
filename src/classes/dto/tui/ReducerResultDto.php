<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui;

/**
 * DTO результата применения события в reducer.
 *
 * Помимо обновлённого состояния может содержать submittedInput — введённый текст,
 * отправленный по Enter (до каких-либо преобразований pre-hook).
 *
 * Пример использования:
 *
 * ```php
 * $result = new ReducerResultDto($state, $submittedInput);
 * if ($result->getSubmittedInput() !== null) { ... }
 * ```
 */
final class ReducerResultDto
{
    public function __construct(
        private readonly TuiStateDto $state,
        private readonly ?string $submittedInput,
    ) {
    }

    public function getState(): TuiStateDto
    {
        return $this->state;
    }

    public function getSubmittedInput(): ?string
    {
        return $this->submittedInput;
    }
}
