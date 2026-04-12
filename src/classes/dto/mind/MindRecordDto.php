<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\mind;

/**
 * DTO одной записи долговременной памяти `.mind`.
 *
 * Используется при чтении блока из файла ({@see \app\modules\neuron\classes\storage\UserMindMarkdownStorage::getByRecordId}),
 * при фильтрации при перестроении и при замене записей ({@see \app\modules\neuron\classes\storage\UserMindMarkdownStorage::replaceByRecordIds}).
 * Поля: монотонный `recordId`, время ISO-8601, ключ сессии, роль NeuronAI (строка) и тело сообщения.
 *
 * Пример:
 *
 * ```php
 * $row = (new MindRecordDto())
 *     ->setRecordId(10)
 *     ->setCapturedAt('2026-04-12T10:00:00+00:00')
 *     ->setSessionKey('20260412-100000-1-0')
 *     ->setRole('user')
 *     ->setBody('Привет');
 * ```
 */
class MindRecordDto
{
    private int $recordId = 0;
    private string $capturedAt = '';
    private string $sessionKey = '';
    private string $role = '';
    private string $body = '';

    /**
     * Возвращает монотонный номер записи (record id).
     */
    public function getRecordId(): int
    {
        return $this->recordId;
    }

    /**
     * Устанавливает номер записи.
     *
     * @param int $recordId Уникальный в пределах пользователя номер записи (при замене — существующий id).
     */
    public function setRecordId(int $recordId): self
    {
        $this->recordId = $recordId;
        return $this;
    }

    /**
     * Возвращает метку времени записи (ISO-8601).
     */
    public function getCapturedAt(): string
    {
        return $this->capturedAt;
    }

    /**
     * Устанавливает метку времени записи.
     *
     * @param string $capturedAt Время в формате ISO-8601.
     */
    public function setCapturedAt(string $capturedAt): self
    {
        $this->capturedAt = $capturedAt;
        return $this;
    }

    /**
     * Возвращает ключ сессии.
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Устанавливает ключ сессии.
     *
     * @param string $sessionKey Идентификатор сессии приложения.
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Возвращает строковое значение роли сообщения.
     */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * Устанавливает роль сообщения.
     *
     * @param string $role Значение роли (как у NeuronAI MessageRole).
     */
    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    /**
     * Возвращает нормализованное тело сообщения.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Устанавливает тело сообщения.
     *
     * @param string $body Текст тела без разделителей блоков (при записи будет нормализовано хранилищем).
     */
    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }
}
