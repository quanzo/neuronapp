<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события TodoList.
 *
 * Содержит данные о текущем пункте списка и переходах goto.
 *
 * Пример использования:
 * ```php
 * $event = (new TodoEventDto())
 *     ->setTodoListName('daily')
 *     ->setTodoIndex(2)
 *     ->setTodo('Check logs');
 * ```
 */
class TodoEventDto extends BaseEventDto
{
    private string $todoListName       = '';
    private int $todoIndex             = 0;
    private string $todo               = '';
    private string $todoAgent          = '';
    private ?int $gotoTargetIndex      = null;
    private ?int $gotoTransitionsCount = null;
    private ?string $reason            = null;

    public function getTodoListName(): string
    {
        return $this->todoListName;
    }

    public function setTodoListName(string $todoListName): self
    {
        $this->todoListName = $todoListName;
        return $this;
    }

    public function getTodoIndex(): int
    {
        return $this->todoIndex;
    }

    public function setTodoIndex(int $todoIndex): self
    {
        $this->todoIndex = $todoIndex;
        return $this;
    }

    public function getTodo(): string
    {
        return $this->todo;
    }

    public function setTodo(string $todo): self
    {
        $this->todo = $todo;
        return $this;
    }

    public function getTodoAgent(): string
    {
        return $this->todoAgent;
    }

    public function setTodoAgent(string $todoAgent): self
    {
        $this->todoAgent = $todoAgent;
        return $this;
    }

    public function getGotoTargetIndex(): ?int
    {
        return $this->gotoTargetIndex;
    }

    public function setGotoTargetIndex(?int $gotoTargetIndex): self
    {
        $this->gotoTargetIndex = $gotoTargetIndex;
        return $this;
    }

    public function getGotoTransitionsCount(): ?int
    {
        return $this->gotoTransitionsCount;
    }

    public function setGotoTransitionsCount(?int $gotoTransitionsCount): self
    {
        $this->gotoTransitionsCount = $gotoTransitionsCount;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
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
            'todoListName' => $this->todoListName,
            'todoIndex' => $this->todoIndex,
            'todo' => $this->todo,
            'todoAgent' => $this->todoAgent,
            'gotoTargetIndex' => $this->gotoTargetIndex,
            'gotoTransitionsCount' => $this->gotoTransitionsCount,
            'reason' => $this->reason,
        ];
    }
}
