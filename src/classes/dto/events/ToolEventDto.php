<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO события инструмента.
 *
 * Содержит имя инструмента, признак успеха и диагностику ошибки.
 *
 * Пример использования:
 * ```php
 * $event = (new ToolEventDto())
 *     ->setToolName('bash')
 *     ->setSuccess(true);
 * ```
 */
class ToolEventDto extends BaseEventDto implements IArrayable
{
    private string $toolName = '';
    private bool $success = true;
    private ?string $errorClass = null;
    private ?string $errorMessage = null;

    /**
     * Возвращает имя инструмента.
     */
    public function getToolName(): string
    {
        return $this->toolName;
    }

    /**
     * Устанавливает имя инструмента.
     */
    public function setToolName(string $toolName): self
    {
        $this->toolName = $toolName;
        return $this;
    }

    /**
     * Возвращает признак успешного выполнения.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Устанавливает признак успешного выполнения.
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
     * Преобразует DTO в массив для логирования/сериализации.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'toolName'     => $this->toolName,
            'success'      => $this->success,
            'errorClass'   => $this->errorClass,
            'errorMessage' => $this->errorMessage,
        ]);
    }
}
