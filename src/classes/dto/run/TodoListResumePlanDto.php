<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\run;

/**
 * DTO плана возобновления TodoList по checkpoint run-state.
 *
 * Содержит уже вычисленное решение:
 * - можно ли делать resume;
 * - с какого индекса продолжать;
 * - доступен ли откат истории;
 * - какая причина у решения.
 *
 * Пример:
 * <code>
 * $plan = (new TodoListResumePlanDto())
 *     ->setResumeAvailable(true)
 *     ->setReason('ready')
 *     ->setStartFromTodoIndex(3);
 * </code>
 */
final class TodoListResumePlanDto
{
    private bool $resumeAvailable = false;
    private string $reason = 'no_checkpoint';
    private int $startFromTodoIndex = 0;
    private ?RunStateDto $runStateDto = null;

    /**
     * Возвращает признак доступности resume.
     */
    public function isResumeAvailable(): bool
    {
        return $this->resumeAvailable;
    }

    /**
     * Устанавливает признак доступности resume.
     *
     * @param bool $resumeAvailable true, если resume можно применять.
     *
     * @return self
     */
    public function setResumeAvailable(bool $resumeAvailable): self
    {
        $this->resumeAvailable = $resumeAvailable;

        return $this;
    }

    /**
     * Возвращает код причины решения.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Устанавливает код причины решения.
     *
     * Примеры: `ready`, `history_missing`, `no_checkpoint`, `finished`,
     * `todolist_mismatch`, `session_mismatch`.
     *
     * @param string $reason Код причины.
     *
     * @return self
     */
    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    /**
     * Возвращает индекс первого todo для продолжения.
     */
    public function getStartFromTodoIndex(): int
    {
        return $this->startFromTodoIndex;
    }

    /**
     * Устанавливает индекс первого todo для продолжения.
     *
     * @param int $startFromTodoIndex Индекс (0-based).
     *
     * @return self
     */
    public function setStartFromTodoIndex(int $startFromTodoIndex): self
    {
        $this->startFromTodoIndex = $startFromTodoIndex;

        return $this;
    }

    /**
     * Возвращает checkpoint DTO, если он был найден.
     */
    public function getRunStateDto(): ?RunStateDto
    {
        return $this->runStateDto;
    }

    /**
     * Устанавливает checkpoint DTO.
     *
     * @param RunStateDto|null $runStateDto DTO checkpoint или null.
     *
     * @return self
     */
    public function setRunStateDto(?RunStateDto $runStateDto): self
    {
        $this->runStateDto = $runStateDto;

        return $this;
    }

    /**
     * Возвращает наличие `history_message_count`, достаточного для отката истории.
     */
    public function hasHistoryRollbackPoint(): bool
    {
        return $this->runStateDto?->getHistoryMessageCount() !== null;
    }

    /**
     * Возвращает количество сообщений для отката истории, если оно есть.
     */
    public function getHistoryMessageCount(): ?int
    {
        return $this->runStateDto?->getHistoryMessageCount();
    }

    /**
     * Возвращает индекс последнего завершённого todo или -1, если checkpoint отсутствует.
     */
    public function getLastCompletedTodoIndex(): int
    {
        return $this->runStateDto?->getLastCompletedTodoIndex() ?? -1;
    }
}
