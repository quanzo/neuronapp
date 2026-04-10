<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события: при resume оркестратором в чекпоинте отсутствует `history_message_count`.
 *
 * Семантически принадлежит домену оркестратора и наследуется от {@see OrchestratorEventDto}.
 * Служит для логирования и наблюдаемости: без усечения истории возможны дубликаты
 * сообщений при продолжении списка.
 *
 * Событие публикуется как warning-уровень (не ошибка — выполнение продолжается).
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
 *
 * echo (string) $dto;
 * // [OrchestratorResumeHistoryMissingEvent] todolist=job-step | lastCompleted=0 | startFrom=1 | ...
 * ```
 */
final class OrchestratorResumeHistoryMissingEventDto extends OrchestratorEventDto
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
        return array_merge(parent::toArray(), [
            'todolistName'            => $this->todolistName,
            'lastCompletedTodoIndex'  => $this->lastCompletedTodoIndex,
            'startFromTodoIndex'      => $this->startFromTodoIndex,
            'reason'                  => 'history_message_count_absent',
        ]);
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        return [
            'todolist'      => $this->todolistName,
            'lastCompleted' => $this->lastCompletedTodoIndex,
            'startFrom'     => $this->startFromTodoIndex,
        ] + parent::buildStringParts();
    }
}
