<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\SessionKeyHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see SessionKeyHelper}.
 *
 * Проверяют канонический формат session key, генерацию базовой и полной формы,
 * а также граничные и заведомо неверные варианты.
 */
final class SessionKeyHelperTest extends TestCase
{
    /**
     * Проверяет валидацию полного session key на наборе из 10+ кейсов.
     */
    #[DataProvider('provideFullSessionKeyCases')]
    public function testIsValidFullSessionKey(string $comment, string $sessionKey, bool $expected): void
    {
        $this->assertSame($expected, SessionKeyHelper::isValid($sessionKey), $comment);
    }

    /**
     * Проверяет валидацию базовой части session key на наборе из 10+ кейсов.
     */
    #[DataProvider('provideBaseSessionKeyCases')]
    public function testIsValidBaseSessionKey(string $comment, string $baseKey, bool $expected): void
    {
        $this->assertSame($expected, SessionKeyHelper::isValidBaseKey($baseKey), $comment);
    }

    /**
     * Полный ключ строится как base key + суффикс userId.
     */
    public function testBuildSessionKeyAppendsUserId(): void
    {
        $sessionKey = SessionKeyHelper::buildSessionKey(42);

        $this->assertTrue(SessionKeyHelper::isValid($sessionKey));
        $this->assertStringEndsWith('-42', $sessionKey);
    }

    /**
     * Пустой userId нормализуется в `0`.
     */
    public function testAppendUserIdNormalizesEmptyToZero(): void
    {
        $fullKey = SessionKeyHelper::appendUserId('20250301-143022-123456', '');

        $this->assertSame('20250301-143022-123456-0', $fullKey);
    }

    /**
     * Описание формата синхронизировано с константами helper.
     */
    public function testDescribeFormatContainsCanonicalFormatAndExample(): void
    {
        $description = SessionKeyHelper::describeFormat();

        $this->assertStringContainsString(SessionKeyHelper::FORMAT, $description);
        $this->assertStringContainsString(SessionKeyHelper::EXAMPLE, $description);
    }

    /**
     * @return iterable<string, array{0:string,1:string,2:bool}>
     */
    public static function provideFullSessionKeyCases(): iterable
    {
        yield 'valid_zero_user' => ['валидный ключ с userId=0', '20250301-143022-123456-0', true];
        yield 'valid_numeric_user' => ['валидный ключ с numeric userId', '20250301-143022-123456-77', true];
        yield 'valid_long_microseconds' => ['валидный ключ с длинной микросекундной частью', '20250301-143022-123456789-9', true];
        yield 'invalid_empty' => ['пустая строка невалидна', '', false];
        yield 'invalid_base_only' => ['базовая часть без userId невалидна как полный ключ', '20250301-143022-123456', false];
        yield 'invalid_missing_microseconds' => ['без микросекундной части ключ невалиден', '20250301-143022-0', false];
        yield 'invalid_bad_date_separators' => ['неверные разделители в дате', '2025-0301-143022-123456-0', false];
        yield 'invalid_alpha_suffix' => ['буквенный userId не допускается', '20250301-143022-123456-user', false];
        yield 'invalid_spaces' => ['пробелы делают ключ невалидным', ' 20250301-143022-123456-0 ', false];
        yield 'invalid_trailing_dash' => ['висячий дефис в конце невалиден', '20250301-143022-123456-', false];
    }

    /**
     * @return iterable<string, array{0:string,1:string,2:bool}>
     */
    public static function provideBaseSessionKeyCases(): iterable
    {
        yield 'valid_base_standard' => ['валидная базовая часть', '20250301-143022-123456', true];
        yield 'valid_base_short_microseconds' => ['микросекунды могут быть короче 6 знаков', '20250301-143022-1', true];
        yield 'valid_base_long_microseconds' => ['микросекунды могут быть длиннее 6 знаков', '20250301-143022-123456789', true];
        yield 'invalid_base_empty' => ['пустая строка невалидна', '', false];
        yield 'invalid_base_with_user' => ['полный ключ не считается базовой частью', '20250301-143022-123456-0', false];
        yield 'invalid_base_bad_time' => ['неполное время делает базовую часть невалидной', '20250301-1430-123456', false];
        yield 'invalid_base_alpha' => ['буквы в микросекундах недопустимы', '20250301-143022-abc', false];
        yield 'invalid_base_spaces' => ['пробелы недопустимы', '20250301-143022-123 456', false];
        yield 'invalid_base_extra_dash' => ['лишний дефис делает ключ невалидным', '20250301-143022--123456', false];
        yield 'invalid_base_random' => ['случайная строка невалидна', 'not-a-session-key', false];
    }
}
