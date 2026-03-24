<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO события оркестратора.
 *
 * Содержит состояние внешнего цикла init/step/finish.
 *
 * Пример использования:
 * ```php
 * $event = (new OrchestratorEventDto())
 *     ->setIterations(2)
 *     ->setRestartCount(0)
 *     ->setReason('completed');
 * ```
 */
class OrchestratorEventDto extends BaseEventDto implements IArrayable
{
    private int $iterations           = 0;
    private int $restartCount         = 0;
    private ?int $completedNormalized = null;
    private mixed $completedRaw       = null;
    private string $reason            = '';
    private bool $success             = false;
    private ?string $errorClass       = null;
    private ?string $errorMessage     = null;

    public function getIterations(): int
    {
        return $this->iterations;
    }

    public function setIterations(int $iterations): self
    {
        $this->iterations = $iterations;
        return $this;
    }

    public function getRestartCount(): int
    {
        return $this->restartCount;
    }

    public function setRestartCount(int $restartCount): self
    {
        $this->restartCount = $restartCount;
        return $this;
    }

    public function getCompletedNormalized(): ?int
    {
        return $this->completedNormalized;
    }

    public function setCompletedNormalized(?int $completedNormalized): self
    {
        $this->completedNormalized = $completedNormalized;
        return $this;
    }

    public function getCompletedRaw(): mixed
    {
        return $this->completedRaw;
    }

    public function setCompletedRaw(mixed $completedRaw): self
    {
        $this->completedRaw = $completedRaw;
        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }

    public function getErrorClass(): ?string
    {
        return $this->errorClass;
    }

    public function setErrorClass(?string $errorClass): self
    {
        $this->errorClass = $errorClass;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + [
            'iterations'          => $this->iterations,
            'restartCount'        => $this->restartCount,
            'completedNormalized' => $this->completedNormalized,
            'completedRaw'        => $this->completedRaw,
            'reason'              => $this->reason,
            'success'             => $this->success,
            'errorClass'          => $this->errorClass,
            'errorMessage'        => $this->errorMessage,
        ];
    }
}
