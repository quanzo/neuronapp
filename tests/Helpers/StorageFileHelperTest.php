<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\StorageFileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see StorageFileHelper}.
 *
 * Проверяют канонический нейминг файлов сессий, run-state, var storage и логов.
 */
final class StorageFileHelperTest extends TestCase
{
    /**
     * Проверяет построение имён файлов на наборе из 10+ кейсов.
     */
    #[DataProvider('provideFileNameCases')]
    public function testCanonicalFileNames(
        string $comment,
        string $method,
        array $arguments,
        string $expected
    ): void {
        $actual = StorageFileHelper::$method(...$arguments);

        $this->assertSame($expected, $actual, $comment);
    }

    /**
     * @return iterable<string, array{0:string,1:string,2:array<int,string|null>,3:string}>
     */
    public static function provideFileNameCases(): iterable
    {
        yield 'session_history_plain' => [
            'общий файл истории сессии без имени агента',
            'sessionHistoryFileName',
            ['20250301-143022-123456-0'],
            'neuron_20250301-143022-123456-0.chat',
        ];

        yield 'session_history_with_agent' => [
            'файл истории с именем агента',
            'sessionHistoryFileName',
            ['20250301-143022-123456-0', 'default'],
            'neuron_20250301-143022-123456-0-default.chat',
        ];

        yield 'session_history_empty_agent' => [
            'пустое имя агента заменяется на unknown',
            'sessionHistoryFileName',
            ['20250301-143022-123456-0', ''],
            'neuron_20250301-143022-123456-0-unknown.chat',
        ];

        yield 'run_state_standard' => [
            'checkpoint run-state для session agent',
            'runStateFileName',
            ['20250301-143022-123456-0', 'session'],
            'run_state_20250301-143022-123456-0_session.json',
        ];

        yield 'run_state_sanitize_agent' => [
            'недопустимые символы в имени агента санитизируются',
            'runStateFileName',
            ['20250301-143022-123456-0', 'agent/main'],
            'run_state_20250301-143022-123456-0_agent_main.json',
        ];

        yield 'var_result_standard' => [
            'файл результата var storage',
            'varResultFileName',
            ['20250301-143022-123456-0', 'completed'],
            'var_20250301-143022-123456-0_completed.json',
        ];

        yield 'var_result_sanitize_name' => [
            'имя переменной санитизируется',
            'varResultFileName',
            ['20250301-143022-123456-0', 'name with spaces'],
            'var_20250301-143022-123456-0_name_with_spaces.json',
        ];

        yield 'var_index_standard' => [
            'индекс-файл var storage',
            'varIndexFileName',
            ['20250301-143022-123456-0'],
            'var_index_20250301-143022-123456-0.json',
        ];

        yield 'log_file_standard' => [
            'лог-файл сессии',
            'sessionLogFileName',
            ['20250301-143022-123456-0'],
            '20250301-143022-123456-0.log',
        ];

        yield 'log_file_sanitize' => [
            'лог-файл санитизирует недопустимые символы',
            'sessionLogFileName',
            ['session/key'],
            'session_key.log',
        ];
    }
}
