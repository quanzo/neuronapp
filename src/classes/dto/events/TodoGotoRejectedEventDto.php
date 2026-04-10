<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события отклонённого goto-перехода в TodoList.
 *
 * Расширяет {@see TodoErrorEventDto}. Переход отклоняется, если:
 * - превышен лимит goto-переходов (`MAX_GOTO_TRANSITIONS`);
 * - целевой индекс вне допустимого диапазона.
 *
 * Используется для события `todo.goto_rejected`.
 *
 * Пример использования:
 * ```php
 * $event = (new TodoGotoRejectedEventDto())
 *     ->setTodoListName('daily')
 *     ->setTodoIndex(5)
 *     ->setGotoTargetIndex(0)
 *     ->setGotoTransitionsCount(11)
 *     ->setReason('too many transitions');
 *
 * echo (string) $event;
 * // [TodoGotoRejectedEvent] list=daily | index=5 | gotoTarget=0 | gotoTransitions=11 | reason="too many..." | ...
 * ```
 */
class TodoGotoRejectedEventDto extends TodoErrorEventDto
{
}
