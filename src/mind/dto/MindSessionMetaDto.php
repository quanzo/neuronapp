<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\dto;

/**
 * DTO метаданных одной сессии в индексе `.mind/<user>/sessions.md`.
 *
 * Важные инварианты:
 * - `summary` хранится в однострочном виде (для стабильной таблицы markdown);
 * - `storageKey` — безопасный ключ файлового набора сессии (см. MindSessionStorageKeyHelper).
 *
 * Пример:
 *
 * <code>
 * $dto = (new MindSessionMetaDto())
 *     ->setSessionKey('20260602-120000-123456-501')
 *     ->setStorageKey('s_20260602-120000-123456-501')
 *     ->setFirstCapturedAt('2026-06-02T12:00:00+00:00')
 *     ->setLastCapturedAt('2026-06-02T12:05:00+00:00')
 *     ->setMessageCount(17)
 *     ->setSummary('Пользователь сообщил имя агента...');
 * </code>
 */
final class MindSessionMetaDto
{
    private string $sessionKey = '';
    private string $storageKey = '';
    private string $firstCapturedAt = '';
    private string $lastCapturedAt = '';
    private int $messageCount = 0;
    private string $summary = '';

    /**
     * Возвращает полный sessionKey.
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Устанавливает полный sessionKey.
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Возвращает storageKey файлового набора сессии.
     */
    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    /**
     * Устанавливает storageKey.
     */
    public function setStorageKey(string $storageKey): self
    {
        $this->storageKey = $storageKey;
        return $this;
    }

    /**
     * Возвращает время первого сообщения (ISO-8601).
     */
    public function getFirstCapturedAt(): string
    {
        return $this->firstCapturedAt;
    }

    /**
     * Устанавливает время первого сообщения (ISO-8601).
     */
    public function setFirstCapturedAt(string $firstCapturedAt): self
    {
        $this->firstCapturedAt = $firstCapturedAt;
        return $this;
    }

    /**
     * Возвращает время последнего сообщения (ISO-8601).
     */
    public function getLastCapturedAt(): string
    {
        return $this->lastCapturedAt;
    }

    /**
     * Устанавливает время последнего сообщения (ISO-8601).
     */
    public function setLastCapturedAt(string $lastCapturedAt): self
    {
        $this->lastCapturedAt = $lastCapturedAt;
        return $this;
    }

    /**
     * Возвращает число сообщений в сессии.
     */
    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    /**
     * Устанавливает число сообщений в сессии.
     */
    public function setMessageCount(int $messageCount): self
    {
        $this->messageCount = max(0, $messageCount);
        return $this;
    }

    /**
     * Возвращает однострочное summary.
     */
    public function getSummary(): string
    {
        return $this->summary;
    }

    /**
     * Устанавливает summary (ожидается однострочное значение).
     */
    public function setSummary(string $summary): self
    {
        $this->summary = $summary;
        return $this;
    }
}
