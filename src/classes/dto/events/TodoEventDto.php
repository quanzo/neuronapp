<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события TodoList.
 *
 * Содержит данные о текущем пункте списка задач, агенте-исполнителе
 * и информацию о переходах goto.
 * Используется для событий `todo.started`, `todo.completed`, `todo.goto_requested`
 * и `todo.agent_switched`.
 * Для события `todo.failed` используется наследник {@see TodoErrorEventDto}.
 * Для события `todo.goto_rejected` используется наследник {@see TodoGotoRejectedEventDto}.
 *
 * Пример использования:
 * ```php
 * $event = (new TodoEventDto())
 *     ->setTodoListName('daily')
 *     ->setTodoIndex(2)
 *     ->setTodo('Check logs');
 *
 * echo (string) $event;
 * // [TodoEvent] list=daily | index=2 | todo="Check logs" | todoAgent= | runId=... | agent=...
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

    /**
     * Возвращает имя TodoList.
     */
    public function getTodoListName(): string
    {
        return $this->todoListName;
    }

    /**
     * Устанавливает имя TodoList.
     *
     * @param string $todoListName Имя файла/идентификатор списка задач.
     */
    public function setTodoListName(string $todoListName): self
    {
        $this->todoListName = $todoListName;
        return $this;
    }

    /**
     * Возвращает индекс текущего пункта (0-based).
     */
    public function getTodoIndex(): int
    {
        return $this->todoIndex;
    }

    /**
     * Устанавливает индекс текущего пункта.
     *
     * @param int $todoIndex Индекс пункта в списке (0-based).
     */
    public function setTodoIndex(int $todoIndex): self
    {
        $this->todoIndex = $todoIndex;
        return $this;
    }

    /**
     * Возвращает текст пункта задачи.
     */
    public function getTodo(): string
    {
        return $this->todo;
    }

    /**
     * Устанавливает текст пункта задачи.
     *
     * @param string $todo Текст пункта задачи.
     */
    public function setTodo(string $todo): self
    {
        $this->todo = $todo;
        return $this;
    }

    /**
     * Возвращает имя агента, исполняющего задачу.
     */
    public function getTodoAgent(): string
    {
        return $this->todoAgent;
    }

    /**
     * Устанавливает имя агента-исполнителя.
     *
     * @param string $todoAgent Имя агента.
     */
    public function setTodoAgent(string $todoAgent): self
    {
        $this->todoAgent = $todoAgent;
        return $this;
    }

    /**
     * Возвращает индекс целевого пункта для goto-перехода или null.
     */
    public function getGotoTargetIndex(): ?int
    {
        return $this->gotoTargetIndex;
    }

    /**
     * Устанавливает индекс целевого пункта для goto.
     *
     * @param ?int $gotoTargetIndex Целевой индекс (0-based) или null.
     */
    public function setGotoTargetIndex(?int $gotoTargetIndex): self
    {
        $this->gotoTargetIndex = $gotoTargetIndex;
        return $this;
    }

    /**
     * Возвращает счётчик выполненных goto-переходов.
     */
    public function getGotoTransitionsCount(): ?int
    {
        return $this->gotoTransitionsCount;
    }

    /**
     * Устанавливает счётчик goto-переходов.
     *
     * @param ?int $gotoTransitionsCount Текущее число переходов или null.
     */
    public function setGotoTransitionsCount(?int $gotoTransitionsCount): self
    {
        $this->gotoTransitionsCount = $gotoTransitionsCount;
        return $this;
    }

    /**
     * Возвращает причину/описание контекста события.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Устанавливает причину/описание контекста.
     *
     * @param ?string $reason Описание причины или null.
     */
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
            'todoListName'         => $this->todoListName,
            'todoIndex'            => $this->todoIndex,
            'todo'                 => $this->todo,
            'todoAgent'            => $this->todoAgent,
            'gotoTargetIndex'      => $this->gotoTargetIndex,
            'gotoTransitionsCount' => $this->gotoTransitionsCount,
            'reason'               => $this->reason,
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        $parts = [
            'list'      => $this->todoListName,
            'index'     => $this->todoIndex,
            'todo'      => $this->buildTodoPreview($this->todo, 120),
            'todoAgent' => $this->todoAgent,
        ];

        if ($this->gotoTargetIndex !== null) {
            $parts['gotoTarget'] = $this->gotoTargetIndex;
        }
        if ($this->gotoTransitionsCount !== null) {
            $parts['gotoTransitions'] = $this->gotoTransitionsCount;
        }
        if ($this->reason !== null && $this->reason !== '') {
            $parts['reason'] = $this->reason;
        }

        return $parts + parent::buildStringParts();
    }

    /**
     * Сокращает текст задачи до maxLength символов для строкового представления.
     */
    private function buildTodoPreview(string $todo, int $maxLength): string
    {
        $todo = trim($todo);
        if ($todo === '') {
            return '';
        }

        $firstLine = preg_replace("/\r\n|\r/u", "\n", $todo) ?? $todo;
        $pos = strpos($firstLine, "\n");
        if ($pos !== false) {
            $firstLine = mb_substr($firstLine, 0, $pos);
        }

        $firstLine = preg_replace('/\s+/u', ' ', $firstLine) ?? $firstLine;
        $firstLine = trim($firstLine);

        if (mb_strlen($firstLine) <= $maxLength) {
            return $firstLine;
        }

        return mb_substr($firstLine, 0, $maxLength) . '...';
    }
}
