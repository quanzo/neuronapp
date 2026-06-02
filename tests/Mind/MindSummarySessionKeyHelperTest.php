<?php

declare(strict_types=1);

namespace Tests\Mind;

use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see MindSummarySessionKeyHelper}: служебный sessionKey для mind-summary.
 */
final class MindSummarySessionKeyHelperTest extends TestCase
{
    /**
     * Проверяет forMainSession на наборе из 10+ кейсов.
     *
     * @param string $comment Пояснение кейса.
     * @param string $main Входной основной ключ.
     * @param string $expected Ожидаемый служебный ключ.
     */
    #[DataProvider('provideForMainSessionCases')]
    public function testForMainSession(string $comment, string $main, string $expected): void
    {
        $this->assertSame($expected, MindSummarySessionKeyHelper::forMainSession($main), $comment);
    }

    /**
     * Проверяет isSummarySession на граничных и ложных суффиксах.
     *
     * @param string $comment Пояснение кейса.
     * @param string $sessionKey Проверяемый ключ.
     * @param bool $expected Ожидаемый результат.
     */
    #[DataProvider('provideIsSummarySessionCases')]
    public function testIsSummarySession(string $comment, string $sessionKey, bool $expected): void
    {
        $this->assertSame($expected, MindSummarySessionKeyHelper::isSummarySession($sessionKey), $comment);
    }

    /**
     * Проверяет mainFromSummary: round-trip и неверные ключи.
     *
     * @param string $comment Пояснение кейса.
     * @param string $summaryKey Служебный или посторонний ключ.
     * @param string|null $expectedMain Ожидаемый основной ключ или null.
     */
    #[DataProvider('provideMainFromSummaryCases')]
    public function testMainFromSummary(string $comment, string $summaryKey, ?string $expectedMain): void
    {
        $this->assertSame($expectedMain, MindSummarySessionKeyHelper::mainFromSummary($summaryKey), $comment);
    }

    /**
     * Суффикс константы совпадает с ожидаемым в документации.
     */
    public function testSuffixConstant(): void
    {
        $this->assertSame(':__mind_summary__', MindSummarySessionKeyHelper::SUFFIX);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideForMainSessionCases(): iterable
    {
        yield 'normal_main' => [
            'обычный ключ сессии',
            '20260602-120000-123456-501',
            '20260602-120000-123456-501:__mind_summary__',
        ];
        yield 'empty_main_unknown' => [
            'пустой main даёт unknown+suffix',
            '',
            'unknown:__mind_summary__',
        ];
        yield 'main_with_underscores' => [
            'подчёркивания в main сохраняются',
            'sess_alpha_99',
            'sess_alpha_99:__mind_summary__',
        ];
        yield 'main_with_dots' => [
            'точки в main допустимы в sessionKey',
            '2026.06.02-run',
            '2026.06.02-run:__mind_summary__',
        ];
        yield 'short_main' => [
            'короткий main',
            'x',
            'x:__mind_summary__',
        ];
        yield 'main_already_has_colons' => [
            'двоеточия в main не путаются с suffix',
            'a:b:c',
            'a:b:c:__mind_summary__',
        ];
        yield 'unicode_main' => [
            'unicode в main',
            'сессия-1',
            'сессия-1:__mind_summary__',
        ];
        yield 'numeric_main' => [
            'только цифры',
            '12345',
            '12345:__mind_summary__',
        ];
        yield 'main_trailing_dash' => [
            'trailing dash',
            'run-',
            'run-:__mind_summary__',
        ];
        yield 'long_main' => [
            'длинный main',
            '20260602-120000-123456789012345-999',
            '20260602-120000-123456789012345-999:__mind_summary__',
        ];
        yield 'main_spaces' => [
            'пробелы в main (редкий кейс)',
            'key with space',
            'key with space:__mind_summary__',
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function provideIsSummarySessionCases(): iterable
    {
        yield 'valid_summary' => [
            'полный служебный ключ',
            '20260602-120000-123456-501:__mind_summary__',
            true,
        ];
        yield 'empty_not_summary' => [
            'пустая строка',
            '',
            false,
        ];
        yield 'main_only' => [
            'только main без suffix',
            '20260602-120000-123456-501',
            false,
        ];
        yield 'wrong_suffix_partial' => [
            'частичный суффикс',
            'main:__mind_summary',
            false,
        ];
        yield 'wrong_suffix_typo' => [
            'опечатка в suffix',
            'main:__mind_summery__',
            false,
        ];
        yield 'suffix_only' => [
            'только suffix',
            ':__mind_summary__',
            true,
        ];
        yield 'unknown_summary' => [
            'unknown+suffix от пустого main',
            'unknown:__mind_summary__',
            true,
        ];
        yield 'embedded_suffix_middle' => [
            'suffix в середине не считается (ends_with)',
            'prefix:__mind_summary__:tail',
            false,
        ];
        yield 'similar_ending' => [
            'похожее окончание',
            'x__mind_summary__',
            false,
        ];
        yield 'double_suffix' => [
            'двойной suffix всё ещё summary',
            'main:__mind_summary__:__mind_summary__',
            true,
        ];
        yield 'main_with_colons' => [
            'main с двоеточиями без suffix',
            'a:b:c',
            false,
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function provideMainFromSummaryCases(): iterable
    {
        yield 'round_trip' => [
            'извлечение main из служебного ключа',
            '20260602-120000-123456-501:__mind_summary__',
            '20260602-120000-123456-501',
        ];
        yield 'not_summary_null' => [
            'обычный ключ — null',
            '20260602-120000-123456-501',
            null,
        ];
        yield 'empty_null' => [
            'пустой — null',
            '',
            null,
        ];
        yield 'unknown_main' => [
            'unknown из пустого main',
            'unknown:__mind_summary__',
            'unknown',
        ];
        yield 'suffix_only_empty_main' => [
            'только suffix — пустой main',
            ':__mind_summary__',
            '',
        ];
        yield 'unicode_round_trip' => [
            'unicode main',
            'сессия-1:__mind_summary__',
            'сессия-1',
        ];
        yield 'partial_suffix_null' => [
            'неполный suffix — null',
            'main:__mind',
            null,
        ];
        yield 'wrong_suffix_null' => [
            'неверный suffix — null',
            'main:summary',
            null,
        ];
        yield 'colon_main' => [
            'main с двоеточиями',
            'a:b:c:__mind_summary__',
            'a:b:c',
        ];
        yield 'whitespace_main' => [
            'main с пробелами',
            'key with space:__mind_summary__',
            'key with space',
        ];
    }
}
