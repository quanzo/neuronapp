<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\MarkdownChunckHelper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Тесты для phrase->regex преобразования в {@see MarkdownChunckHelper}.
 *
 * Проверяют:
 * - очистку фразы от "не слов";
 * - удаление одиночных букв;
 * - гибридную обрезку окончаний;
 * - формирование цепочного regex с расстоянием между словами.
 */
final class MarkdownChunckHelperPhraseRegexTest extends TestCase
{
    /**
     * Проверяет построение regex из произвольной фразы.
     *
     * @param string $input
     * @param string $expected
     */
    #[DataProvider('buildLineRegexFromPhraseProvider')]
    public function testBuildLineRegexFromPhrase(string $input, string $expected): void
    {
        $method = new ReflectionMethod(MarkdownChunckHelper::class, 'buildLineRegex');
        $method->setAccessible(true);
        $actual = $method->invoke(null, $input);

        $this->assertSame($expected, $actual);
    }

    /**
     * Проверяет строгий режим преобразования plain-text в literal-regex "как есть".
     */
    public function testBuildLineRegexStrictModeBuildsLiteralRegex(): void
    {
        $method = new ReflectionMethod(MarkdownChunckHelper::class, 'buildLineRegex');
        $method->setAccessible(true);
        $actual = $method->invoke(null, 'коэффициент локализация', true);

        $this->assertSame('/коэффициент локализация/iu', $actual);
    }

    /**
     * Проверяет strict-режим на "ненормальных" символах: ничего не нормализуем, только literal-экранирование.
     */
    public function testBuildLineRegexStrictModeWithSpecialAndControlChars(): void
    {
        $method = new ReflectionMethod(MarkdownChunckHelper::class, 'buildLineRegex');
        $method->setAccessible(true);
        $actual = $method->invoke(null, "weird\tline/with.*chars\x00", true);

        $this->assertSame("/weird\tline\/with\.\*chars\\000/iu", $actual);
    }

    /**
     * Набор сценариев phrase->regex.
     *
     * @return array<string, array{input:string, expected:string}>
     */
    public static function buildLineRegexFromPhraseProvider(): array
    {
        return [
            // 1. Базовый пример из требований.
            'base phrase with stemming' => [
                'input' => 'коэффициент локализация производимая продукция расчеты результаты',
                'expected' => '/коэффициент[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+локализаци[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+производ[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+продукци[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+расчет[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+результат[\p{L}\p{N}_]*/ui',
            ],
            // 2. Пунктуация и спецсимволы должны быть удалены.
            'strip punctuation and symbols' => [
                'input' => 'Локализация, продукция; расчеты?!',
                'expected' => '/локализаци[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+продукци[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+расчет[\p{L}\p{N}_]*/ui',
            ],
            // 3. Одиночные буквы должны быть удалены.
            'remove single letters' => [
                'input' => 'а локализация б продукция в',
                'expected' => '/локализаци[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+продукци[\p{L}\p{N}_]*/ui',
            ],
            // 4. Короткие слова длиной <= 4 не обрезаются.
            'do not trim short words' => [
                'input' => 'тест план код идея',
                'expected' => '/тест[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+план[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+код[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+идея[\p{L}\p{N}_]*/ui',
            ],
            // 5. Окончание -ами должно срезаться.
            'trim ending ami' => [
                'input' => 'расчетами',
                'expected' => '/расчет[\p{L}\p{N}_]*/ui',
            ],
            // 6. Окончание -ия должно срезаться.
            'trim ending iya' => [
                'input' => 'локализация',
                'expected' => '/локализаци[\p{L}\p{N}_]*/ui',
            ],
            // 7. Неизвестное длинное слово на согласную не fallback-режется.
            'keep unknown long consonant ending' => [
                'input' => 'коэффициент',
                'expected' => '/коэффициент[\p{L}\p{N}_]*/ui',
            ],
            // 8. Неизвестное слово на гласную получает fallback -1 символ.
            'fallback trim vowel ending' => [
                'input' => 'проверко',
                'expected' => '/проверк[\p{L}\p{N}_]*/ui',
            ],
            // 9. Смешанный регистр нормализуется.
            'normalize case' => [
                'input' => 'ЛОКАЛИЗАЦИЯ ПРОДУКЦИЯ',
                'expected' => '/локализаци[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+продукци[\p{L}\p{N}_]*/ui',
            ],
            // 10. Цифровые токены остаются как слова.
            'keep numeric tokens' => [
                'input' => 'форма 2024 результаты',
                'expected' => '/форм[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+2024[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+результат[\p{L}\p{N}_]*/ui',
            ],
            // 11. Непечатаемые символы между словами не должны ломать токенизацию.
            'control chars between words are ignored' => [
                'input' => "коэффициент\x00\x01 локализация\x07",
                'expected' => '/коэффициент[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+локализаци[\p{L}\p{N}_]*/ui',
            ],
            // 12. Шум из спецсимволов должен быть отброшен в phrase->regex режиме.
            'special symbols noise removed' => [
                'input' => "!!!@@@### коэффициент $$$%%% локализация ^^^&&&",
                'expected' => '/коэффициент[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+локализаци[\p{L}\p{N}_]*/ui',
            ],
        ];
    }

    /**
     * Проверяет, что пустой или "мусорный" ввод приводит к исключению.
     *
     * @param string $input
     */
    #[DataProvider('invalidPhraseProvider')]
    public function testBuildLineRegexFromPhraseThrowsOnInvalidInput(string $input): void
    {
        $method = new ReflectionMethod(MarkdownChunckHelper::class, 'buildLineRegex');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $method->invoke(null, $input);
    }

    /**
     * Набор заведомо невалидного ввода.
     *
     * @return array<string, array{input:string}>
     */
    public static function invalidPhraseProvider(): array
    {
        return [
            // 1. Пустая строка.
            'empty string' => ['input' => ''],
            // 2. Только пробелы.
            'spaces only' => ['input' => '     '],
            // 3. Только пунктуация.
            'punctuation only' => ['input' => '!!!,,,;;;'],
            // 4. Только одиночные буквы.
            'single letters only' => ['input' => 'а б в г д'],
            // 5. Только управляющие/спецсимволы без валидных слов.
            'controls and symbols only' => ['input' => "\x00\x01\x02@@@###"],
        ];
    }
}
