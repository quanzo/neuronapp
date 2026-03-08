<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\run;

/**
 * DTO состояния выполнения run (чекпоинт) списка заданий TodoList в рамках сессии.
 *
 * Хранит идентификаторы сессии и агента, имя списка, индекс последнего завершённого todo,
 * количество сообщений в истории чата на момент последнего завершённого todo (для отката при resume)
 * и признак завершения всего списка.
 */
final class RunStateDto
{
    private string $sessionKey = '';
    private string $agentName = '';
    private string $runId = '';
    private string $todolistName = '';
    private string $startedAt = '';
    private int $lastCompletedTodoIndex = -1;
    private ?int $historyMessageCount = null;
    private bool $finished = false;

    /**
     * Возвращает базовый ключ сессии (формат buildSessionKey).
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Устанавливает ключ сессии.
     *
     * @param string $sessionKey Базовый ключ сессии (например, Ymd-His-u).
     * @return self
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Возвращает имя агента.
     */
    public function getAgentName(): string
    {
        return $this->agentName;
    }

    /**
     * Устанавливает имя агента.
     *
     * @param string $agentName Имя агента LLM.
     * @return self
     */
    public function setAgentName(string $agentName): self
    {
        $this->agentName = $agentName;
        return $this;
    }

    /**
     * Возвращает идентификатор текущего запуска (например, ключ лог-файла).
     */
    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * Устанавливает идентификатор запуска.
     *
     * @param string $runId Уникальный идентификатор run.
     * @return self
     */
    public function setRunId(string $runId): self
    {
        $this->runId = $runId;
        return $this;
    }

    /**
     * Возвращает имя списка заданий (TodoList).
     */
    public function getTodolistName(): string
    {
        return $this->todolistName;
    }

    /**
     * Устанавливает имя списка заданий.
     *
     * @param string $todolistName Имя TodoList (например, из файла в todos/).
     * @return self
     */
    public function setTodolistName(string $todolistName): self
    {
        $this->todolistName = $todolistName;
        return $this;
    }

    /**
     * Возвращает время старта run в формате ISO 8601.
     */
    public function getStartedAt(): string
    {
        return $this->startedAt;
    }

    /**
     * Устанавливает время старта.
     *
     * @param string $startedAt Время в формате ISO 8601.
     * @return self
     */
    public function setStartedAt(string $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    /**
     * Возвращает индекс последнего полностью завершённого todo (−1 если ни один не завершён).
     */
    public function getLastCompletedTodoIndex(): int
    {
        return $this->lastCompletedTodoIndex;
    }

    /**
     * Устанавливает индекс последнего завершённого todo.
     *
     * @param int $lastCompletedTodoIndex Индекс (0-based) или −1.
     * @return self
     */
    public function setLastCompletedTodoIndex(int $lastCompletedTodoIndex): self
    {
        $this->lastCompletedTodoIndex = $lastCompletedTodoIndex;
        return $this;
    }

    /**
     * Возвращает число сообщений в истории чата на момент последнего Todo completed, или null (старый формат).
     */
    public function getHistoryMessageCount(): ?int
    {
        return $this->historyMessageCount;
    }

    /**
     * Устанавливает количество сообщений в истории для отката при resume.
     *
     * @param int|null $historyMessageCount Количество сообщений или null.
     * @return self
     */
    public function setHistoryMessageCount(?int $historyMessageCount): self
    {
        $this->historyMessageCount = $historyMessageCount;
        return $this;
    }

    /**
     * Возвращает признак успешного завершения всего списка (TodoList completed).
     */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * Устанавливает признак завершения списка.
     *
     * @param bool $finished true, если TodoList выполнен полностью.
     * @return self
     */
    public function setFinished(bool $finished): self
    {
        $this->finished = $finished;
        return $this;
    }

    /**
     * Преобразует состояние в массив для сериализации в JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_key'               => $this->sessionKey,
            'agent_name'                => $this->agentName,
            'run_id'                    => $this->runId,
            'todolist_name'             => $this->todolistName,
            'started_at'                => $this->startedAt,
            'last_completed_todo_index' => $this->lastCompletedTodoIndex,
            'history_message_count'     => $this->historyMessageCount,
            'finished'                  => $this->finished,
        ];
    }

    /**
     * Создаёт DTO из массива (например, после json_decode).
     *
     * @param array<string, mixed> $data Массив с ключами, соответствующими полям DTO.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $dto                         = new self();
        $dto->sessionKey             = (string) ($data['session_key'] ?? '');
        $dto->agentName              = (string) ($data['agent_name'] ?? '');
        $dto->runId                  = (string) ($data['run_id'] ?? '');
        $dto->todolistName           = (string) ($data['todolist_name'] ?? '');
        $dto->startedAt              = (string) ($data['started_at'] ?? '');
        $dto->lastCompletedTodoIndex = (int) ($data['last_completed_todo_index'] ?? -1);
        $dto->historyMessageCount    = isset($data['history_message_count'])
            ? (int) $data['history_message_count']
            :  null;
        $dto->finished = (bool) ($data['finished'] ?? false);
        return $dto;
    }
}
