<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * Базовый DTO события.
 *
 * Содержит общие поля event-контекста и используется как родитель
 * для специализированных DTO событий.
 *
 * Пример использования:
 * ```php
 * $dto = (new BaseEventDto())
 *     ->setSessionKey('20260324-120000-123456-0')
 *     ->setRunId('abc123');
 * ```
 */
class BaseEventDto
{
    private string $sessionKey = '';
    private string $runId = '';
    private string $timestamp = '';

    /**
     * Возвращает ключ сессии.
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Устанавливает ключ сессии.
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Возвращает идентификатор run.
     */
    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * Устанавливает идентификатор run.
     */
    public function setRunId(string $runId): self
    {
        $this->runId = $runId;
        return $this;
    }

    /**
     * Возвращает время события в формате ATOM.
     */
    public function getTimestamp(): string
    {
        return $this->timestamp;
    }

    /**
     * Устанавливает время события в формате ATOM.
     */
    public function setTimestamp(string $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    /**
     * Преобразует DTO в массив для логирования/сериализации.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sessionKey' => $this->sessionKey,
            'runId' => $this->runId,
            'timestamp' => $this->timestamp,
        ];
    }
}
