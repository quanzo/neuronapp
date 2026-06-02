<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\dto;

/**
 * DTO результата массовой пересборки summary для хранилища `.mind`.
 *
 * Используется {@see \app\modules\neuron\mind\storage\UserMindStorage::refreshAllSessionSummaries()}.
 *
 * Пример:
 *
 * <code>
 * $result = (new MindStorageSummaryRefreshResultDto())
 *     ->incrementAttempted()
 *     ->incrementUpdated();
 * $result->getAttempted(); // 1
 * </code>
 */
final class MindStorageSummaryRefreshResultDto
{
    private int $attempted = 0;

    private int $updated = 0;

    private int $skipped = 0;

    /**
     * Возвращает число сессий, для которых вызывалась суммаризация.
     */
    public function getAttempted(): int
    {
        return $this->attempted;
    }

    /**
     * Возвращает число сессий, у которых summary обновился.
     */
    public function getUpdated(): int
    {
        return $this->updated;
    }

    /**
     * Возвращает число пропущенных сессий (служебные ключи, пустые и т.п.).
     */
    public function getSkipped(): int
    {
        return $this->skipped;
    }

    /**
     * Увеличивает счётчик попыток суммаризации.
     */
    public function incrementAttempted(): self
    {
        ++$this->attempted;
        return $this;
    }

    /**
     * Увеличивает счётчик успешно обновлённых summary.
     */
    public function incrementUpdated(): self
    {
        ++$this->updated;
        return $this;
    }

    /**
     * Увеличивает счётчик пропущенных сессий.
     */
    public function incrementSkipped(): self
    {
        ++$this->skipped;
        return $this;
    }
}
