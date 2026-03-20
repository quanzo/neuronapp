<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\MarkdownHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see MarkdownHelper}.
 *
 * MarkdownHelper нормализует пробелы в markdown-тексте и при этом:
 *  - не изменяет строки внутри fenced-блоков кода;
 *  - сохраняет hard line break (два пробела в конце строки);
 *  - схлопывает серии из 3+ пробелов в обычном тексте;
 *  - удаляет лишние конечные пробелы.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\MarkdownHelper}
 */
class MarkdownHelperTest extends TestCase
{
    /**
     * Пустая строка обрабатывается без ошибок и возвращается как есть.
     */
    public function testSafeMarkdownWhitespaceEmptyString(): void
    {
        $this->assertSame('', MarkdownHelper::safeMarkdownWhitespace(''));
    }

    /**
     * Обычный текст без лишних пробелов не должен изменяться.
     */
    public function testSafeMarkdownWhitespacePlainTextUnchanged(): void
    {
        $input = "Hello world\nSecond line";
        $this->assertSame($input, MarkdownHelper::safeMarkdownWhitespace($input));
    }

    /**
     * В обычном тексте последовательность из 3+ пробелов схлопывается до одного.
     */
    public function testSafeMarkdownWhitespaceCollapsesThreeAndMoreSpaces(): void
    {
        $input = 'A   B    C';
        $this->assertSame('A B C', MarkdownHelper::safeMarkdownWhitespace($input));
    }

    /**
     * Два пробела внутри строки не считаются "лишними" и сохраняются.
     */
    public function testSafeMarkdownWhitespacePreservesDoubleSpacesInsideLine(): void
    {
        $input = 'A  B';
        $this->assertSame('A  B', MarkdownHelper::safeMarkdownWhitespace($input));
    }

    /**
     * Hard line break в markdown (ровно два пробела в конце строки) сохраняется.
     */
    public function testSafeMarkdownWhitespacePreservesHardLineBreak(): void
    {
        $input = "first line  \nsecond line";
        $this->assertSame($input, MarkdownHelper::safeMarkdownWhitespace($input));
    }

    /**
     * Хвостовые пробелы в конце строки удаляются, если это не hard line break.
     */
    public function testSafeMarkdownWhitespaceTrimsTrailingSpacesWithoutHardBreak(): void
    {
        $input = "line with tail   \nnext";
        $expected = "line with tail\nnext";
        $this->assertSame($expected, MarkdownHelper::safeMarkdownWhitespace($input));
    }

    /**
     * Внутри fenced-блока (``` ... ```) строки должны оставаться без изменений.
     */
    public function testSafeMarkdownWhitespaceDoesNotChangeContentInsideBacktickFence(): void
    {
        $input = "before   text\n```\ncode    keep   spacing   \n```\nafter   text";
        $expected = "before text\n```\ncode    keep   spacing   \n```\nafter text";
        $this->assertSame($expected, MarkdownHelper::safeMarkdownWhitespace($input));
    }

    /**
     * Внутри fenced-блока с тильдами (~~~ ... ~~~) содержимое также не меняется.
     */
    public function testSafeMarkdownWhitespaceDoesNotChangeContentInsideTildeFence(): void
    {
        $input = "~~~\n  code    block   \n~~~";
        $this->assertSame($input, MarkdownHelper::safeMarkdownWhitespace($input));
    }

    /**
     * Строки fence (```/~~~) сохраняются как есть, включая пробелы после маркера.
     */
    public function testSafeMarkdownWhitespacePreservesFenceLineFormatting(): void
    {
        $input = "```   \ncode\n```";
        $this->assertSame($input, MarkdownHelper::safeMarkdownWhitespace($input));
    }

    /**
     * Незакрытый fenced-блок переключает режим до конца текста, и оставшиеся
     * строки не обрабатываются.
     */
    public function testSafeMarkdownWhitespaceUnclosedFenceKeepsRestUntouched(): void
    {
        $input = "text   outside\n```\ncode    line\ntail    line";
        $expected = "text outside\n```\ncode    line\ntail    line";
        $this->assertSame($expected, MarkdownHelper::safeMarkdownWhitespace($input));
    }

    /**
     * Лишние пробелы в пустых строках удаляются до пустой строки.
     */
    public function testSafeMarkdownWhitespaceWhitespaceOnlyLineBecomesEmpty(): void
    {
        $input = "before\n   \nafter";
        $expected = "before\n\nafter";
        $this->assertSame($expected, MarkdownHelper::safeMarkdownWhitespace($input));
    }
}
