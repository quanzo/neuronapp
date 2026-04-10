<?php

declare(strict_types=1);

namespace app\modules\neuron\traits;

/**
 * Трейт для DTO событий ошибок.
 *
 * Предоставляет реализацию интерфейса {@see \app\modules\neuron\interfaces\IErrorEvent}:
 * поля errorClass и errorMessage, а также вспомогательные методы для сериализации
 * ошибки в массив и в строковые пары key=value.
 *
 * Пример использования:
 * ```php
 * class SkillErrorEventDto extends SkillEventDto implements IErrorEvent
 * {
 *     use HasErrorInfoTrait;
 * }
 *
 * $dto = (new SkillErrorEventDto())
 *     ->setErrorClass(\RuntimeException::class)
 *     ->setErrorMessage('timeout');
 *
 * // Распознавание по интерфейсу:
 * if ($dto instanceof IErrorEvent) { ... }
 * ```
 */
trait HasErrorInfoTrait
{
    private ?string $errorClass = null;
    private ?string $errorMessage = null;

    /**
     * Возвращает FQCN класса исключения.
     */
    public function getErrorClass(): ?string
    {
        return $this->errorClass;
    }

    /**
     * Устанавливает FQCN класса исключения.
     *
     * @param ?string $errorClass Полное имя класса ошибки или null.
     */
    public function setErrorClass(?string $errorClass): static
    {
        $this->errorClass = $errorClass;
        return $this;
    }

    /**
     * Возвращает текст сообщения об ошибке.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Устанавливает текст сообщения об ошибке.
     *
     * @param ?string $errorMessage Текст ошибки или null.
     */
    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    /**
     * Возвращает массив с полями ошибки для слияния в toArray().
     *
     * @return array<string, mixed>
     */
    protected function errorInfoToArray(): array
    {
        return [
            'errorClass'   => $this->errorClass,
            'errorMessage' => $this->errorMessage,
        ];
    }

    /**
     * Возвращает строковые пары ошибки для buildStringParts().
     *
     * Формат: `error=ClassName: "message"` или `error=ClassName` (если сообщение пустое).
     *
     * @return array<string, string>
     */
    protected function buildErrorStringParts(): array
    {
        if ($this->errorClass === null && $this->errorMessage === null) {
            return [];
        }

        $parts = [];
        if ($this->errorClass !== null) {
            $lastSlash = strrchr($this->errorClass, '\\');
            $short = $lastSlash !== false ? substr($lastSlash, 1) : $this->errorClass;
            $value = $this->errorMessage !== null && $this->errorMessage !== ''
                ? $short . ': "' . $this->errorMessage . '"'
                : $short;
            $parts['error'] = $value;
        } elseif ($this->errorMessage !== null) {
            $parts['error'] = '"' . $this->errorMessage . '"';
        }

        return $parts;
    }
}
