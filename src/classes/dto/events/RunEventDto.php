<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события уровня run.
 *
 * Хранит метаданные запуска skill/todolist.
 *
 * Пример использования:
 * ```php
 * $event = (new RunEventDto())
 *     ->setType('todolist')
 *     ->setName('review')
 *     ->setSteps(3);
 * ```
 */
class RunEventDto extends BaseEventDto
{
    private string $type = '';
    private string $name = '';
    private int $steps = 0;
    private bool $success = false;
    private ?string $errorClass = null;
    private ?string $errorMessage = null;

    /**
     * Возвращает тип запуска.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Устанавливает тип запуска.
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Возвращает имя выполняемого сценария.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Устанавливает имя выполняемого сценария.
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Возвращает число выполненных шагов.
     */
    public function getSteps(): int
    {
        return $this->steps;
    }

    /**
     * Устанавливает число выполненных шагов.
     */
    public function setSteps(int $steps): self
    {
        $this->steps = $steps;
        return $this;
    }

    /**
     * Возвращает признак успешности.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Устанавливает признак успешности.
     */
    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }

    /**
     * Возвращает класс ошибки.
     */
    public function getErrorClass(): ?string
    {
        return $this->errorClass;
    }

    /**
     * Устанавливает класс ошибки.
     */
    public function setErrorClass(?string $errorClass): self
    {
        $this->errorClass = $errorClass;
        return $this;
    }

    /**
     * Возвращает текст ошибки.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Устанавливает текст ошибки.
     */
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
            'type' => $this->type,
            'name' => $this->name,
            'steps' => $this->steps,
            'success' => $this->success,
            'errorClass' => $this->errorClass,
            'errorMessage' => $this->errorMessage,
        ];
    }
}
