<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IErrorEvent;
use app\modules\neuron\traits\HasErrorInfoTrait;

/**
 * DTO события ошибки пункта TodoList.
 *
 * Расширяет {@see TodoEventDto} полями ошибки (errorClass, errorMessage).
 * Реализует {@see IErrorEvent} для единообразного распознавания ошибочных событий.
 * Используется для события `todo.failed`.
 * Для события `todo.goto_rejected` используется наследник {@see TodoGotoRejectedEventDto}.
 *
 * Пример использования:
 * ```php
 * $event = (new TodoErrorEventDto())
 *     ->setTodoListName('daily')
 *     ->setTodoIndex(2)
 *     ->setTodo('Run tests')
 *     ->setErrorClass(\RuntimeException::class)
 *     ->setErrorMessage('process killed');
 *
 * echo (string) $event;
 * // [TodoErrorEvent] list=daily | index=2 | todo="Run tests" | error=RuntimeException: "process killed" | ...
 * ```
 */
class TodoErrorEventDto extends TodoEventDto implements IErrorEvent
{
    use HasErrorInfoTrait;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + $this->errorInfoToArray();
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        $own    = $this->buildErrorStringParts();
        $parent = parent::buildStringParts();

        $base = [];
        foreach ($parent as $key => $value) {
            $base[$key] = $value;
            if ($key === 'todoAgent') {
                foreach ($own as $k => $v) {
                    $base[$k] = $v;
                }
            }
        }

        return $base ?: array_merge($own, $parent);
    }
}
