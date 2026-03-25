<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\orchestrator;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO результата выполнения оркестратора TodoList-циклов.
 *
 * Содержит итоговый статус запуска, причину завершения и технические метрики
 * (количество итераций, перезапусков, нормализованное значение completed).
 *
 * Пример использования:
 * ```php
 * $result = (new OrchestratorResultDto())
 *     ->setSuccess(true)
 *     ->setReason('completed')
 *     ->setIterations(12);
 * ```
 */
final class OrchestratorResultDto implements IArrayable
{
    private bool $success = false;
    private string $reason = '';
    private int $iterations = 0;
    private int $restartCount = 0;
    private mixed $completedRaw = null;
    private ?int $completedNormalized = null;
    private string $sessionKey = '';

    /**
     * Признак успешного завершения цикла.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Устанавливает признак успешного завершения.
     *
     * @param bool $success true, если цикл завершен штатно.
     */
    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }

    /**
     * Техническая причина завершения.
     *
     * Возможные значения: completed|max_iterations|error|finish_error и т.п.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Устанавливает техническую причину завершения.
     */
    public function setReason(string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    /**
     * Количество выполненных шагов step в текущем запуске.
     */
    public function getIterations(): int
    {
        return $this->iterations;
    }

    /**
     * Устанавливает количество выполненных итераций.
     */
    public function setIterations(int $iterations): self
    {
        $this->iterations = $iterations;
        return $this;
    }

    /**
     * Возвращает число перезапусков цикла после ошибок.
     */
    public function getRestartCount(): int
    {
        return $this->restartCount;
    }

    /**
     * Устанавливает количество перезапусков цикла.
     */
    public function setRestartCount(int $restartCount): self
    {
        $this->restartCount = $restartCount;
        return $this;
    }

    /**
     * Сырое значение completed, загруженное из VarStorage.
     */
    public function getCompletedRaw(): mixed
    {
        return $this->completedRaw;
    }

    /**
     * Устанавливает сырое значение completed.
     */
    public function setCompletedRaw(mixed $completedRaw): self
    {
        $this->completedRaw = $completedRaw;
        return $this;
    }

    /**
     * Нормализованный completed: 1 (исполнено), 0 (не исполнено) или null.
     */
    public function getCompletedNormalized(): ?int
    {
        return $this->completedNormalized;
    }

    /**
     * Устанавливает нормализованное значение completed.
     */
    public function setCompletedNormalized(?int $completedNormalized): self
    {
        $this->completedNormalized = $completedNormalized;
        return $this;
    }

    /**
     * Ключ сессии, в которой выполнялся цикл.
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Устанавливает sessionKey.
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Преобразует результат в массив для сериализации.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'success'             => $this->success,
            'reason'              => $this->reason,
            'iterations'          => $this->iterations,
            'restartCount'        => $this->restartCount,
            'completedRaw'        => $this->completedRaw,
            'completedNormalized' => $this->completedNormalized,
            'sessionKey'          => $this->sessionKey,
        ];
    }
}
