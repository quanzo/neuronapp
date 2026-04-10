<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события оркестратора.
 *
 * Содержит состояние внешнего цикла оркестратора: количество итераций,
 * число рестартов, нормализованное/сырое значение завершённости и причину.
 * Используется для событий `orchestrator.cycle_started`, `orchestrator.step_completed`,
 * `orchestrator.completed` и `orchestrator.restarted`.
 * Для события `orchestrator.failed` используется наследник {@see OrchestratorErrorEventDto}.
 *
 * Пример использования:
 * ```php
 * $event = (new OrchestratorEventDto())
 *     ->setIterations(2)
 *     ->setRestartCount(0)
 *     ->setReason('completed');
 *
 * echo (string) $event;
 * // [OrchestratorEvent] iterations=2 | restarts=0 | reason=completed | runId=... | agent=...
 * ```
 */
class OrchestratorEventDto extends BaseEventDto
{
    private int $iterations           = 0;
    private int $restartCount         = 0;
    private ?int $completedNormalized = null;
    private mixed $completedRaw       = null;
    private string $reason            = '';

    /**
     * Возвращает число выполненных итераций внешнего цикла.
     */
    public function getIterations(): int
    {
        return $this->iterations;
    }

    /**
     * Устанавливает число выполненных итераций.
     *
     * @param int $iterations Текущее число итераций.
     */
    public function setIterations(int $iterations): self
    {
        $this->iterations = $iterations;
        return $this;
    }

    /**
     * Возвращает число рестартов (повторных попыток внешнего цикла).
     */
    public function getRestartCount(): int
    {
        return $this->restartCount;
    }

    /**
     * Устанавливает число рестартов.
     *
     * @param int $restartCount Количество рестартов.
     */
    public function setRestartCount(int $restartCount): self
    {
        $this->restartCount = $restartCount;
        return $this;
    }

    /**
     * Возвращает нормализованное значение завершённости (0-100 или null).
     */
    public function getCompletedNormalized(): ?int
    {
        return $this->completedNormalized;
    }

    /**
     * Устанавливает нормализованное значение завершённости.
     *
     * @param ?int $completedNormalized Процент завершения (0-100) или null.
     */
    public function setCompletedNormalized(?int $completedNormalized): self
    {
        $this->completedNormalized = $completedNormalized;
        return $this;
    }

    /**
     * Возвращает «сырое» значение завершённости из LLM-ответа.
     */
    public function getCompletedRaw(): mixed
    {
        return $this->completedRaw;
    }

    /**
     * Устанавливает «сырое» значение завершённости.
     *
     * @param mixed $completedRaw Значение, полученное от LLM (число, строка, null).
     */
    public function setCompletedRaw(mixed $completedRaw): self
    {
        $this->completedRaw = $completedRaw;
        return $this;
    }

    /**
     * Возвращает причину/описание текущего состояния.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Устанавливает причину/описание текущего состояния.
     *
     * @param string $reason Описание причины (напр. `completed`, `max_iterations`).
     */
    public function setReason(string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + [
            'iterations'          => $this->iterations,
            'restartCount'        => $this->restartCount,
            'completedNormalized' => $this->completedNormalized,
            'completedRaw'        => $this->completedRaw,
            'reason'              => $this->reason,
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        return [
            'iterations' => $this->iterations,
            'restarts'   => $this->restartCount,
            'completed'  => $this->completedNormalized ?? '',
            'reason'     => $this->reason,
        ] + parent::buildStringParts();
    }
}
