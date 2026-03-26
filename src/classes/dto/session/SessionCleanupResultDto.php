<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\session;

/**
 * DTO результата очистки сессии.
 *
 * Инкапсулирует статистику удаления файлов, привязанных к sessionKey.
 *
 * Пример использования:
 *
 * <code>
 * $result = (new SessionCleanupResultDto())
 *     ->setSessionKey('20250301-143022-123456-0')
 *     ->setDryRun(true)
 *     ->addPlannedFile('/tmp/.sessions/neuron_20250301-...chat')
 *     ->incMissingFilesCount();
 * </code>
 */
final class SessionCleanupResultDto
{
    private string $sessionKey = '';
    private bool $dryRun = false;

    /** @var string[] */
    private array $plannedFiles = [];

    /** @var string[] */
    private array $deletedFiles = [];

    /** @var string[] */
    private array $missingFiles = [];

    /** @var string[] */
    private array $errors = [];

    /**
     * Возвращает sessionKey, для которого выполнялась очистка.
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Устанавливает sessionKey.
     *
     * @return $this
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Возвращает true, если выполнен dry-run (без реального удаления).
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Устанавливает режим dry-run.
     *
     * @return $this
     */
    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    /**
     * Список файлов, которые планировалось обработать (удалить).
     *
     * @return string[]
     */
    public function getPlannedFiles(): array
    {
        return $this->plannedFiles;
    }

    /**
     * Добавляет файл в список планируемых к удалению.
     *
     * @return $this
     */
    public function addPlannedFile(string $path): self
    {
        $this->plannedFiles[] = $path;
        return $this;
    }

    /**
     * Возвращает список реально удалённых файлов.
     *
     * @return string[]
     */
    public function getDeletedFiles(): array
    {
        return $this->deletedFiles;
    }

    /**
     * Добавляет файл в список удалённых.
     *
     * @return $this
     */
    public function addDeletedFile(string $path): self
    {
        $this->deletedFiles[] = $path;
        return $this;
    }

    /**
     * Возвращает список отсутствующих файлов (кандидатов, которых не оказалось на диске).
     *
     * @return string[]
     */
    public function getMissingFiles(): array
    {
        return $this->missingFiles;
    }

    /**
     * Добавляет файл в список отсутствующих.
     *
     * @return $this
     */
    public function addMissingFile(string $path): self
    {
        $this->missingFiles[] = $path;
        return $this;
    }

    /**
     * Возвращает список ошибок удаления.
     *
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Добавляет сообщение об ошибке.
     *
     * @return $this
     */
    public function addError(string $message): self
    {
        $this->errors[] = $message;
        return $this;
    }

    /**
     * Возвращает число запланированных к обработке файлов.
     */
    public function getPlannedFilesCount(): int
    {
        return count($this->plannedFiles);
    }

    /**
     * Возвращает число удалённых файлов.
     */
    public function getDeletedFilesCount(): int
    {
        return count($this->deletedFiles);
    }

    /**
     * Возвращает число отсутствующих файлов.
     */
    public function getMissingFilesCount(): int
    {
        return count($this->missingFiles);
    }

    /**
     * Возвращает число ошибок.
     */
    public function getErrorsCount(): int
    {
        return count($this->errors);
    }
}
