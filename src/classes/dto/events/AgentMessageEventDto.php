<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO события отправки сообщения агентом.
 *
 * Описывает цикл отправки сообщения в LLM с учётом вложений и результата.
 */
class AgentMessageEventDto extends BaseEventDto implements IArrayable
{
    private int $attachmentsCount = 0;
    private bool $structured = false;
    private float $durationSeconds = 0.0;
    private bool $success = false;
    private ?string $errorClass = null;
    private ?string $errorMessage = null;

    public function getAttachmentsCount(): int
    {
        return $this->attachmentsCount;
    }

    public function setAttachmentsCount(int $attachmentsCount): self
    {
        $this->attachmentsCount = $attachmentsCount;
        return $this;
    }

    public function isStructured(): bool
    {
        return $this->structured;
    }

    public function setStructured(bool $structured): self
    {
        $this->structured = $structured;
        return $this;
    }

    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(float $durationSeconds): self
    {
        $this->durationSeconds = $durationSeconds;
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
            'attachmentsCount' => $this->attachmentsCount,
            'structured'       => $this->structured,
            'durationSeconds'  => $this->durationSeconds,
            'success'          => $this->success,
            'errorClass'       => $this->errorClass,
            'errorMessage'     => $this->errorMessage,
        ];
    }
}
