<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO события Skill.
 *
 * Содержит ссылку на объект навыка и диагностическую информацию выполнения.
 *
 * Пример использования:
 * ```php
 * $event = (new SkillEventDto())
 *     ->setSkill($skill)
 *     ->setSuccess(true);
 * ```
 */
class SkillEventDto extends BaseEventDto implements IArrayable
{
    private ?Skill $skill         = null;
    private bool $success         = false;
    private ?string $errorClass   = null;
    private ?string $errorMessage = null;

    public function getSkillName(): string
    {
        return $this->skill?->getName() ?? '';
    }

    public function getSkill(): ?Skill
    {
        return $this->skill;
    }

    public function setSkill(Skill $skill): self
    {
        $this->skill = $skill;
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
            'skillName' => $this->getSkillName(),
            'success' => $this->success,
            'errorClass' => $this->errorClass,
            'errorMessage' => $this->errorMessage,
        ];
    }
}
