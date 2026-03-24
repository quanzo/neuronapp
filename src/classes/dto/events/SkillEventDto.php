<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO события Skill.
 *
 * Содержит имя навыка и диагностическую информацию выполнения.
 *
 * Пример использования:
 * ```php
 * $event = (new SkillEventDto())
 *     ->setSkillName('search')
 *     ->setSuccess(true);
 * ```
 */
class SkillEventDto extends BaseEventDto implements IArrayable
{
    private string $skillName     = '';
    private bool $success         = false;
    private ?string $errorClass   = null;
    private ?string $errorMessage = null;

    public function getSkillName(): string
    {
        return $this->skillName;
    }

    public function setSkillName(string $skillName): self
    {
        $this->skillName = $skillName;
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
            'skillName' => $this->skillName,
            'success' => $this->success,
            'errorClass' => $this->errorClass,
            'errorMessage' => $this->errorMessage,
        ];
    }
}
