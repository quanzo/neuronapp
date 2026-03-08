<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\run\RunStateDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see RunStateDto}.
 *
 * RunStateDto — DTO состояния выполнения run (чекпоинт) TodoList в рамках сессии.
 * Хранит session_key, agent_name, run_id, todolist_name, started_at,
 * last_completed_todo_index, history_message_count и finished.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\dto\run\RunStateDto}
 */
class RunStateDtoTest extends TestCase
{
    /**
     * Флюент-сеттеры возвращают self и значения сохраняются.
     */
    public function testFluentSettersAndGetters(): void
    {
        $dto = (new RunStateDto())
            ->setSessionKey('20250308-120000-1')
            ->setAgentName('default')
            ->setRunId('run-1')
            ->setTodolistName('code-review')
            ->setStartedAt('2025-03-08T12:00:00+00:00')
            ->setLastCompletedTodoIndex(2)
            ->setHistoryMessageCount(10)
            ->setFinished(false);

        $this->assertSame('20250308-120000-1', $dto->getSessionKey());
        $this->assertSame('default', $dto->getAgentName());
        $this->assertSame('run-1', $dto->getRunId());
        $this->assertSame('code-review', $dto->getTodolistName());
        $this->assertSame('2025-03-08T12:00:00+00:00', $dto->getStartedAt());
        $this->assertSame(2, $dto->getLastCompletedTodoIndex());
        $this->assertSame(10, $dto->getHistoryMessageCount());
        $this->assertFalse($dto->isFinished());

        $dto->setFinished(true);
        $this->assertTrue($dto->isFinished());
    }

    /**
     * Граничное значение last_completed_todo_index = -1 (ни один todo не завершён).
     */
    public function testLastCompletedTodoIndexMinusOne(): void
    {
        $dto = (new RunStateDto())
            ->setSessionKey('s')
            ->setAgentName('a')
            ->setLastCompletedTodoIndex(-1);
        $this->assertSame(-1, $dto->getLastCompletedTodoIndex());
    }

    /**
     * history_message_count может быть null (старый формат чекпоинта).
     */
    public function testHistoryMessageCountNull(): void
    {
        $dto = (new RunStateDto())
            ->setSessionKey('s')
            ->setAgentName('a')
            ->setHistoryMessageCount(null);
        $this->assertNull($dto->getHistoryMessageCount());
    }

    /**
     * toArray() возвращает все поля, пригодные для JSON.
     */
    public function testToArray(): void
    {
        $dto = (new RunStateDto())
            ->setSessionKey('sk')
            ->setAgentName('ag')
            ->setRunId('rid')
            ->setTodolistName('tl')
            ->setStartedAt('2025-01-01T00:00:00+00:00')
            ->setLastCompletedTodoIndex(0)
            ->setHistoryMessageCount(5)
            ->setFinished(true);

        $arr = $dto->toArray();
        $this->assertSame('sk', $arr['session_key']);
        $this->assertSame('ag', $arr['agent_name']);
        $this->assertSame('rid', $arr['run_id']);
        $this->assertSame('tl', $arr['todolist_name']);
        $this->assertSame('2025-01-01T00:00:00+00:00', $arr['started_at']);
        $this->assertSame(0, $arr['last_completed_todo_index']);
        $this->assertSame(5, $arr['history_message_count']);
        $this->assertTrue($arr['finished']);
    }

    /**
     * fromArray() восстанавливает DTO из массива; отсутствующие ключи дают значения по умолчанию.
     */
    public function testFromArray(): void
    {
        $arr = [
            'session_key' => 's1',
            'agent_name' => 'a1',
            'run_id' => 'r1',
            'todolist_name' => 't1',
            'started_at' => '2025-06-01T12:00:00+00:00',
            'last_completed_todo_index' => 3,
            'history_message_count' => 20,
            'finished' => false,
        ];
        $dto = RunStateDto::fromArray($arr);
        $this->assertSame('s1', $dto->getSessionKey());
        $this->assertSame('a1', $dto->getAgentName());
        $this->assertSame('r1', $dto->getRunId());
        $this->assertSame('t1', $dto->getTodolistName());
        $this->assertSame('2025-06-01T12:00:00+00:00', $dto->getStartedAt());
        $this->assertSame(3, $dto->getLastCompletedTodoIndex());
        $this->assertSame(20, $dto->getHistoryMessageCount());
        $this->assertFalse($dto->isFinished());
    }

    /**
     * fromArray() с отсутствующим history_message_count даёт null (обратная совместимость).
     */
    public function testFromArrayWithoutHistoryMessageCount(): void
    {
        $arr = [
            'session_key' => 's',
            'agent_name' => 'a',
            'run_id' => 'r',
            'todolist_name' => 't',
            'started_at' => '2025-01-01T00:00:00+00:00',
            'last_completed_todo_index' => 0,
            'finished' => false,
        ];
        $dto = RunStateDto::fromArray($arr);
        $this->assertNull($dto->getHistoryMessageCount());
    }

    /**
     * fromArray() с пустым массивом — граничный случай, все поля по умолчанию.
     */
    public function testFromArrayEmpty(): void
    {
        $dto = RunStateDto::fromArray([]);
        $this->assertSame('', $dto->getSessionKey());
        $this->assertSame('', $dto->getAgentName());
        $this->assertSame(-1, $dto->getLastCompletedTodoIndex());
        $this->assertNull($dto->getHistoryMessageCount());
        $this->assertFalse($dto->isFinished());
    }

    /**
     * round-trip: toArray затем fromArray даёт эквивалентное состояние.
     */
    public function testRoundTripToArrayFromArray(): void
    {
        $dto = (new RunStateDto())
            ->setSessionKey('key')
            ->setAgentName('agent')
            ->setRunId('run-id')
            ->setTodolistName('mylist')
            ->setStartedAt('2025-03-08T10:00:00+00:00')
            ->setLastCompletedTodoIndex(1)
            ->setHistoryMessageCount(7)
            ->setFinished(false);

        $restored = RunStateDto::fromArray($dto->toArray());
        $this->assertSame($dto->getSessionKey(), $restored->getSessionKey());
        $this->assertSame($dto->getAgentName(), $restored->getAgentName());
        $this->assertSame($dto->getLastCompletedTodoIndex(), $restored->getLastCompletedTodoIndex());
        $this->assertSame($dto->getHistoryMessageCount(), $restored->getHistoryMessageCount());
        $this->assertSame($dto->isFinished(), $restored->isFinished());
    }
}
