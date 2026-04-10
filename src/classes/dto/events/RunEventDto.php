<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события уровня run.
 *
 * Хранит метаданные запуска (тип, имя сценария, количество выполненных шагов).
 * Используется для событий `run.started` и `run.finished`.
 * Для события `run.failed` используется наследник {@see RunErrorEventDto}.
 *
 * Пример использования:
 * ```php
 * $event = (new RunEventDto())
 *     ->setType('todolist')
 *     ->setName('review')
 *     ->setSteps(3);
 *
 * echo (string) $event;
 * // [RunEvent] type=todolist | name=review | steps=3 | runId=... | agent=...
 * ```
 */
class RunEventDto extends BaseEventDto
{
    private string $type = '';
    private string $name = '';
    private int $steps   = 0;

    /**
     * Возвращает тип запуска (`todolist`, `skill` и т.д.).
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Устанавливает тип запуска.
     *
     * @param string $type Тип запуска (`todolist`, `skill`).
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Возвращает имя выполняемого сценария.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Устанавливает имя выполняемого сценария.
     *
     * @param string $name Имя TodoList или Skill.
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Возвращает число выполненных шагов.
     */
    public function getSteps(): int
    {
        return $this->steps;
    }

    /**
     * Устанавливает число выполненных шагов.
     *
     * @param int $steps Количество пройденных шагов.
     */
    public function setSteps(int $steps): self
    {
        $this->steps = $steps;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + [
            'type'  => $this->type,
            'name'  => $this->name,
            'steps' => $this->steps,
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        return [
            'type'  => $this->type,
            'name'  => $this->name,
            'steps' => $this->steps,
        ] + parent::buildStringParts();
    }
}
