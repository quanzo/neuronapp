<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO события: при resume оркестратором в чекпоинте нет `history_message_count`.
 *
 * Служит для логирования и наблюдаемости: без усечения истории возможны дубликаты
 * сообщений при продолжении списка.
 *
 * Пример использования:
 * ```php
 * $dto = (new OrchestratorResumeHistoryMissingEventDto())
 *     ->setSessionKey('20260324-120000-1-0')
 *     ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
 *     ->setAgent($agentCfg)
 *     ->setTodolistName('job-step')
 *     ->setLastCompletedTodoIndex(0)
 *     ->setStartFromTodoIndex(1);
 * ```
 */
final class OrchestratorResumeHistoryMissingEventDto extends BaseEventDto implements IArrayable
{
    private string $todolistName = '';

    private int $lastCompletedTodoIndex = -1;

    private int $startFromTodoIndex = 0;

    /**
     * Возвращает имя списка TodoList (`RunStateDto::todolist_name`).
     */
    public function getTodolistName(): string
    {
        return $this->todolistName;
    }

    /**
     * Устанавливает имя списка TodoList.
     *
     * @param string $todolistName Имя списка заданий.
     */
    public function setTodolistName(string $todolistName): self
    {
        $this->todolistName = $todolistName;

        return $this;
    }

    /**
     * Возвращает индекс последнего успешно завершённого todo из чекпоинта.
     */
    public function getLastCompletedTodoIndex(): int
    {
        return $this->lastCompletedTodoIndex;
    }

    /**
     * Устанавливает индекс последнего завершённого todo из чекпоинта.
     *
     * @param int $lastCompletedTodoIndex Индекс (0-based) или -1, если ни один пункт не завершён.
     */
    public function setLastCompletedTodoIndex(int $lastCompletedTodoIndex): self
    {
        $this->lastCompletedTodoIndex = $lastCompletedTodoIndex;

        return $this;
    }

    /**
     * Возвращает индекс первого todo, с которого продолжится выполнение.
     */
    public function getStartFromTodoIndex(): int
    {
        return $this->startFromTodoIndex;
    }

    /**
     * Устанавливает индекс первого todo для `TodoList::execute()`.
     *
     * @param int $startFromTodoIndex Обычно `last_completed_todo_index + 1`.
     */
    public function setStartFromTodoIndex(int $startFromTodoIndex): self
    {
        $this->startFromTodoIndex = $startFromTodoIndex;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + [
            'todolistName'            => $this->todolistName,
            'lastCompletedTodoIndex'  => $this->lastCompletedTodoIndex,
            'startFromTodoIndex'      => $this->startFromTodoIndex,
            'reason'                  => 'history_message_count_absent',
        ];
    }
}
