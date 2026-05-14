<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\helpers\PromptOptionsParseHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see PromptOptionsParseHelper}: многострочный JSON в блоке опций Skill/TodoList.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\PromptOptionsParseHelper}
 */
class PromptOptionsParseHelperTest extends TestCase
{
    /**
     * Пустой фрагмент — не считаем многострочным JSON.
     */
    public function testShouldTryEmptyFragmentReturnsFalse(): void
    {
        $this->assertFalse(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation(''));
        $this->assertFalse(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation('   '));
    }

    /**
     * Строка не с «{»/«[» — не пытаемся склеивать.
     */
    public function testShouldTryPlainStringReturnsFalse(): void
    {
        $this->assertFalse(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation('hello'));
        $this->assertFalse(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation('"quoted"'));
    }

    /**
     * Однострочный валидный объект — json_decode успешен, многострочность не нужна.
     */
    public function testShouldTryValidSingleLineObjectReturnsFalse(): void
    {
        $this->assertFalse(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation('{"a":1}'));
    }

    /**
     * Однострочный валидный массив — не нужна многострочность.
     */
    public function testShouldTryValidSingleLineArrayReturnsFalse(): void
    {
        $this->assertFalse(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation('[1,2,3]'));
    }

    /**
     * Незакрытая «{» — нужна попытка многострочного продолжения.
     */
    public function testShouldTryIncompleteObjectBraceReturnsTrue(): void
    {
        $this->assertTrue(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation('{'));
    }

    /**
     * Незакрытый массив «[» — нужна многострочность.
     */
    public function testShouldTryIncompleteArrayBracketReturnsTrue(): void
    {
        $this->assertTrue(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation('['));
    }

    /**
     * Невалидный JSON в одной строке, но структурно «открыт» — decode падает, начинается с «{».
     */
    public function testShouldTryBrokenButOpenObjectReturnsTrue(): void
    {
        $this->assertTrue(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation('{"a":'));
    }

    /**
     * Однострочный заведомо битый JSON без открывающей скобки — false.
     */
    public function testShouldTryInvalidJsonNotStartingWithBraceReturnsFalse(): void
    {
        $this->assertFalse(PromptOptionsParseHelper::shouldTryMultilineJsonContinuation('not json'));
    }

    /**
     * Pretty-объект на нескольких строках склеивается и декодируется.
     */
    public function testAccumulatePrettyMultilineObject(): void
    {
        $lines = [
            'params: {',
            '  "query": {',
            '    "type": "string",',
            '    "required": true',
            '  }',
            '}',
            'tools: wiki',
        ];
        [$combined, $extra] = PromptOptionsParseHelper::accumulateMultilineJsonValue($lines, 0, '{');
        $this->assertSame(5, $extra);
        $decoded = JsonHelper::decodeAssociative(trim($combined));
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('query', $decoded);
        $this->assertSame('string', $decoded['query']['type']);
        $this->assertTrue($decoded['query']['required']);
    }

    /**
     * При незакрытом «{» следующая строка как новая опция не подклеивается.
     */
    public function testAccumulateStopsBeforeNextOptionLine(): void
    {
        $lines = [
            'params: {',
            'tools: wiki_search',
        ];
        [$combined, $extra] = PromptOptionsParseHelper::accumulateMultilineJsonValue($lines, 0, '{');
        $this->assertSame(0, $extra);
        $this->assertSame('{', trim($combined));
        JsonHelper::decodeAssociative(trim($combined));
        $this->assertNotSame(JSON_ERROR_NONE, json_last_error());
    }

    /**
     * Многострочный массив JSON.
     */
    public function testAccumulateMultilineArray(): void
    {
        $lines = [
            'tags: [',
            '  "a",',
            '  "b"',
            ']',
        ];
        [$combined, $extra] = PromptOptionsParseHelper::accumulateMultilineJsonValue($lines, 0, '[');
        $this->assertSame(3, $extra);
        $this->assertSame(['a', 'b'], JsonHelper::decodeAssociative(trim($combined)));
    }

    /**
     * Вложенный объект внутри многострочного params — валидный JSON после склейки.
     */
    public function testAccumulateNestedObjectMultiline(): void
    {
        $lines = [
            'params: {',
            '  "q": {',
            '    "type": "string",',
            '    "description": "plain text"',
            '  }',
            '}',
        ];
        [$combined, $extra] = PromptOptionsParseHelper::accumulateMultilineJsonValue($lines, 0, '{');
        $this->assertSame(5, $extra);
        $decoded = JsonHelper::decodeAssociative(trim($combined));
        $this->assertSame('plain text', $decoded['q']['description']);
    }

    /**
     * Структурно завершённый, но синтаксически неверный JSON — не едим строки дальше.
     */
    public function testAccumulateMalformedClosedObjectDoesNotConsumeFollowingLines(): void
    {
        $lines = [
            'params: {bad json}',
            'tools: x',
        ];
        [$combined, $extra] = PromptOptionsParseHelper::accumulateMultilineJsonValue($lines, 0, '{bad json}');
        $this->assertSame(0, $extra);
        JsonHelper::decodeAssociative(trim($combined));
        $this->assertNotSame(JSON_ERROR_NONE, json_last_error());
    }

    /**
     * Пустые строки между частями JSON допускаются (trim + decode).
     */
    public function testAccumulateWithBlankContinuationLines(): void
    {
        $lines = [
            'params: {',
            '',
            '  "x": 1',
            '',
            '}',
        ];
        [$combined, $extra] = PromptOptionsParseHelper::accumulateMultilineJsonValue($lines, 0, '{');
        $this->assertGreaterThanOrEqual(3, $extra);
        $this->assertSame(['x' => 1], JsonHelper::decodeAssociative(trim($combined)));
    }

    /**
     * shouldAppendLine: внутри незакрытой строки разрешаем строку вида «opt: val» (продолжение строки).
     */
    public function testShouldAppendWhenInsideUnclosedString(): void
    {
        $buffer = '{"msg":"start';
        $this->assertTrue(PromptOptionsParseHelper::shouldAppendLineAsJsonContinuation($buffer, 'fake: line'));
    }

    /**
     * shouldAppendLine: вне строки строка «name:» — запрет подклеивания.
     */
    public function testShouldAppendRejectsOptionLikeLineOutsideString(): void
    {
        $buffer = '{"a":1';
        $this->assertFalse(PromptOptionsParseHelper::shouldAppendLineAsJsonContinuation($buffer, 'tools: wiki'));
    }

    /**
     * Строка продолжения с кавычкой в начале (без ведущих пробелов) — не опция, разрешаем.
     */
    public function testShouldAppendAllowsQuotedLineWithoutLeadingSpace(): void
    {
        $buffer = '{';
        $this->assertTrue(PromptOptionsParseHelper::shouldAppendLineAsJsonContinuation($buffer, '"query": 1'));
    }
}
