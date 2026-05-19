<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\OptionsHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see OptionsHelper}.
 *
 * OptionsHelper — статический хелпер для преобразования значений опций.
 * toBool() приводит значение опции к boolean (1/'1'/'true' → true, 0/'0'/'false' → false).
 * parseScalar(), formatScalar() и unescapeString() — литералы аргументов @@-команд.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\OptionsHelper}
 */
class OptionsHelperTest extends TestCase
{
    /**
     * null — false.
     */
    public function testToBoolNullReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(null));
    }

    /**
     * 0 — false.
     */
    public function testToBoolZeroReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(0));
    }

    /**
     * false — false.
     */
    public function testToBoolFalseReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(false));
    }

    /**
     * Строка 'false' — false.
     */
    public function testToBoolStringFalseReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool('false'));
    }

    /**
     * 1 — true.
     */
    public function testToBoolOneReturnsTrue(): void
    {
        $this->assertTrue(OptionsHelper::toBool(1));
    }

    /**
     * true — true.
     */
    public function testToBoolTrueReturnsTrue(): void
    {
        $this->assertTrue(OptionsHelper::toBool(true));
    }

    /**
     * Строка 'true' — true.
     */
    public function testToBoolStringTrueReturnsTrue(): void
    {
        $this->assertTrue(OptionsHelper::toBool('true'));
    }

    /**
     * Строка '1' — true.
     */
    public function testToBoolStringOneReturnsTrue(): void
    {
        $this->assertTrue(OptionsHelper::toBool('1'));
    }

    /**
     * Строка '0' — false.
     */
    public function testToBoolStringZeroReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool('0'));
    }

    /**
     * Неожиданное строковое значение ('yes') — false.
     */
    public function testToBoolUnexpectedStringReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool('yes'));
    }

    /**
     * Пустая строка — false.
     */
    public function testToBoolEmptyStringReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(''));
    }

    /**
     * Массив — false.
     */
    public function testToBoolArrayReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool([]));
    }

    /**
     * Число, отличное от 0 и 1 (например 42) — false.
     */
    public function testToBoolOtherNumberReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(42));
    }

    /**
     * Наборы входов для {@see OptionsHelper::parseScalar()}.
     *
     * @return array<string, array{string, mixed}>
     */
    public static function parseScalarProvider(): array
    {
        return [
            'double quoted string' => ['"hello"', 'hello'],
            'single quoted string' => ["'world'", 'world'],
            'escaped quote in double quotes' => ['"say \"hi\""', 'say "hi"'],
            'escaped backslash' => ['"a\\\\b"', 'a\\b'],
            'true literal' => ['true', true],
            'false literal' => ['false', false],
            'null literal' => ['null', null],
            'positive int' => ['42', 42],
            'negative int' => ['-7', -7],
            'float' => ['2.5', 2.5],
            'bare word' => ['maybe', 'maybe'],
            'empty string' => ['', ''],
            'single char' => ['x', 'x'],
        ];
    }

    /**
     * parseScalar() корректно разбирает литералы команд и граничные значения.
     */
    public function testParseScalar(): void
    {
        foreach (self::parseScalarProvider() as $caseName => [$input, $expected]) {
            $this->assertSame(
                $expected,
                OptionsHelper::parseScalar($input),
                'Failed parseScalar for case: ' . $caseName,
            );
        }
    }

    /**
     * unescapeString() снимает экранирование кавычки и обратного слэша.
     */
    public function testUnescapeStringBasicEscapes(): void
    {
        $this->assertSame('say "hi"', OptionsHelper::unescapeString('say \\"hi\\"', '"'));
        $this->assertSame('a\\b', OptionsHelper::unescapeString('a\\\\b', '"'));
    }

    /**
     * Незакрытый обратный слэш в конце дописывается в результат.
     */
    public function testUnescapeStringTrailingBackslash(): void
    {
        $this->assertSame('path\\', OptionsHelper::unescapeString('path\\', '"'));
    }

    /**
     * Апостроф внутри строки с разделителем " не требует экранирования.
     */
    public function testUnescapeStringOtherQuoteTypeInside(): void
    {
        $this->assertSame("it's", OptionsHelper::unescapeString("it's", '"'));
    }

    /**
     * unescapeString() с одинарной внешней кавычкой обрабатывает двойные кавычки как обычные символы.
     */
    public function testUnescapeStringSingleQuoteDelimiter(): void
    {
        $this->assertSame('"x"', OptionsHelper::unescapeString('"x"', "'"));
    }

    /**
     * Наборы входов для {@see OptionsHelper::formatScalar()}.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function formatScalarProvider(): array
    {
        return [
            'null' => [null, 'null'],
            'true' => [true, 'true'],
            'false' => [false, 'false'],
            'int' => [42, '42'],
            'negative int' => [-1, '-1'],
            'float' => [2.5, '2.5'],
            'plain string' => ['hello', '"hello"'],
            'string with quote' => ['say "hi"', '"say \"hi\""'],
            'string with backslash' => ['a\\b', '"a\\\\b"'],
            'empty string' => ['', '""'],
        ];
    }

    /**
     * formatScalar() строит канонические литералы для сигнатур @@-команд.
     */
    public function testFormatScalar(): void
    {
        foreach (self::formatScalarProvider() as $caseName => [$value, $expected]) {
            $this->assertSame(
                $expected,
                OptionsHelper::formatScalar($value),
                'Failed formatScalar for case: ' . $caseName,
            );
        }
    }

    /**
     * formatScalar() и parseScalar() — взаимно обратные операции для типичных значений.
     */
    public function testFormatScalarRoundTripWithParseScalar(): void
    {
        $values = [null, true, false, 0, 1, -3, 2.5, 'text', 'a"b', 'line\\n'];

        foreach ($values as $value) {
            $literal = OptionsHelper::formatScalar($value);
            $parsed = OptionsHelper::parseScalar($literal);
            $this->assertSame($value, $parsed, 'Round-trip failed for: ' . var_export($value, true));
        }
    }
}
