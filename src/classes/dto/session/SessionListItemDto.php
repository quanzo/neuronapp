<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\session;

/**
 * DTO элемента списка сессий приложения.
 *
 * Используется для выдачи агрегированных метаданных по сессии без чтения всей истории:
 * - ключ сессии;
 * - путь к файлу истории;
 * - время последнего изменения;
 * - размер файла;
 * - наличие чекпоинта выполнения run (TodoList) в `.store`.
 *
 * Пример использования:
 *
 * <code>
 * $item = (new SessionListItemDto())
 *     ->setSessionKey('20250301-143022-123456')
 *     ->setChatFilePath('/app/.sessions/neuron_20250301-143022-123456.chat')
 *     ->setUpdatedAt(time())
 *     ->setSizeBytes(1234)
 *     ->setHasRunCheckpoint(true);
 * </code>
 */
final class SessionListItemDto
{
    private string $sessionKey = '';
    private string $chatFilePath = '';
    private int $updatedAt = 0;
    private int $sizeBytes = 0;
    private bool $hasRunCheckpoint = false;

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
     * @return $this
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Возвращает путь к файлу истории чата (`neuron_<sessionKey>.chat`).
     */
    public function getChatFilePath(): string
    {
        return $this->chatFilePath;
    }

    /**
     * Устанавливает путь к файлу истории чата.
     *
     * @return $this
     */
    public function setChatFilePath(string $chatFilePath): self
    {
        $this->chatFilePath = $chatFilePath;
        return $this;
    }

    /**
     * Возвращает unix timestamp последнего изменения файла истории.
     */
    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }

    /**
     * Устанавливает unix timestamp последнего изменения файла истории.
     *
     * @return $this
     */
    public function setUpdatedAt(int $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Возвращает размер файла истории в байтах.
     */
    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    /**
     * Устанавливает размер файла истории в байтах.
     *
     * @return $this
     */
    public function setSizeBytes(int $sizeBytes): self
    {
        $this->sizeBytes = $sizeBytes;
        return $this;
    }

    /**
     * Возвращает признак наличия чекпоинта незавершённого/завершённого run в `.store`.
     */
    public function hasRunCheckpoint(): bool
    {
        return $this->hasRunCheckpoint;
    }

    /**
     * Устанавливает признак наличия чекпоинта run.
     *
     * @return $this
     */
    public function setHasRunCheckpoint(bool $hasRunCheckpoint): self
    {
        $this->hasRunCheckpoint = $hasRunCheckpoint;
        return $this;
    }
}
