<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\events\OrchestratorResumeHistoryMissingEventDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see OrchestratorResumeHistoryMissingEventDto}: сериализация и граничные значения.
 */
final class OrchestratorResumeHistoryMissingEventDtoTest extends TestCase
{
    /**
     * toArray содержит ожидаемые ключи и значения.
     */
    #[DataProvider('provideToArrayCases')]
    public function testToArrayContainsExpectedKeys(
        string $comment,
        OrchestratorResumeHistoryMissingEventDto $dto,
        array $expectedSubset
    ): void {
        $arr = $dto->toArray();
        foreach ($expectedSubset as $key => $value) {
            $this->assertArrayHasKey($key, $arr, $comment);
            $this->assertSame($value, $arr[$key], $comment);
        }
        $this->assertSame('history_message_count_absent', $arr['reason'], $comment);
    }

    /**
     * Набор из 10+ сценариев для {@see testToArrayContainsExpectedKeys}.
     *
     * @return iterable<string, array{0:string,1:OrchestratorResumeHistoryMissingEventDto,2:array<string,mixed>}>
     */
    public static function provideToArrayCases(): iterable
    {
        $mk = static function (): OrchestratorResumeHistoryMissingEventDto {
            return (new OrchestratorResumeHistoryMissingEventDto())
                ->setSessionKey('sk')
                ->setRunId('rid')
                ->setTimestamp('2026-01-01T00:00:00+00:00');
        };

        yield 'minimal_indices' => [
            'индексы по умолчанию',
            $mk()->setTodolistName('a')->setLastCompletedTodoIndex(-1)->setStartFromTodoIndex(0),
            ['todolistName' => 'a', 'lastCompletedTodoIndex' => -1, 'startFromTodoIndex' => 0],
        ];

        yield 'positive_last' => [
            'положительный last_completed',
            $mk()->setTodolistName('b')->setLastCompletedTodoIndex(0)->setStartFromTodoIndex(1),
            ['lastCompletedTodoIndex' => 0, 'startFromTodoIndex' => 1],
        ];

        yield 'large_indices' => [
            'большие индексы',
            $mk()->setTodolistName('c')->setLastCompletedTodoIndex(999)->setStartFromTodoIndex(1000),
            ['lastCompletedTodoIndex' => 999, 'startFromTodoIndex' => 1000],
        ];

        yield 'unicode_name' => [
            'имя списка с unicode',
            $mk()->setTodolistName('шаг-обзор')->setLastCompletedTodoIndex(1)->setStartFromTodoIndex(2),
            ['todolistName' => 'шаг-обзор'],
        ];

        yield 'empty_name' => [
            'пустое имя списка (граница)',
            $mk()->setTodolistName('')->setLastCompletedTodoIndex(0)->setStartFromTodoIndex(1),
            ['todolistName' => ''],
        ];

        yield 'session_key_preserved' => [
            'sessionKey в базовой части',
            $mk()->setSessionKey('sess-x')->setTodolistName('d')->setLastCompletedTodoIndex(2)->setStartFromTodoIndex(3),
            ['sessionKey' => 'sess-x'],
        ];

        yield 'run_id_preserved' => [
            'runId в базовой части',
            $mk()->setRunId('run-y')->setTodolistName('e')->setLastCompletedTodoIndex(3)->setStartFromTodoIndex(4),
            ['runId' => 'run-y'],
        ];

        yield 'timestamp_preserved' => [
            'timestamp ATOM',
            $mk()->setTimestamp('2026-06-15T08:30:00+03:00')->setTodolistName('f')->setLastCompletedTodoIndex(0)->setStartFromTodoIndex(1),
            ['timestamp' => '2026-06-15T08:30:00+03:00'],
        ];

        yield 'last_equals_start_minus_one' => [
            'согласованность last и start',
            $mk()->setTodolistName('g')->setLastCompletedTodoIndex(4)->setStartFromTodoIndex(5),
            ['lastCompletedTodoIndex' => 4, 'startFromTodoIndex' => 5],
        ];

        yield 'zero_start_explicit' => [
            'явный старт с нуля после last -1',
            $mk()->setTodolistName('h')->setLastCompletedTodoIndex(-1)->setStartFromTodoIndex(0),
            ['startFromTodoIndex' => 0],
        ];

        yield 'negative_last_not_clamped_in_dto' => [
            'отрицательный last (как в повреждённом чекпоине)',
            $mk()->setTodolistName('i')->setLastCompletedTodoIndex(-5)->setStartFromTodoIndex(0),
            ['lastCompletedTodoIndex' => -5],
        ];
    }
}
