<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\CommentsHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see CommentsHelper}.
 *
 * CommentsHelper — статический хелпер для удаления PHP-стиля комментариев
 * из произвольных текстовых блоков.
 * Поддерживает:
 *  - однострочные комментарии (// до конца строки);
 *  - многострочные блочные комментарии;
 *  - удаление хвостовых пробелов/табов в конце строк;
 *  - удаление хвостовых переводов строк в конце текста.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\CommentsHelper}
 */
class CommentsHelperTest extends TestCase
{
    /**
     * Пустая строка на входе — пустая строка на выходе, без ошибок.
     */
    public function testStripCommentsEmptyString(): void
    {
        $this->assertSame('', CommentsHelper::stripComments(''));
    }

    /**
     * Текст без комментариев возвращается неизменённым.
     */
    public function testStripCommentsNoComments(): void
    {
        $this->assertSame('Hello world', CommentsHelper::stripComments('Hello world'));
    }

    /**
     * Однострочный комментарий (// ...) в конце строки удаляется,
     * содержимое до комментария сохраняется.
     */
    public function testStripSingleLineComment(): void
    {
        $result = CommentsHelper::stripComments("code // this is a comment\nnext line");
        $this->assertSame("code\nnext line", $result);
    }

    /**
     * Однострочный комментарий, занимающий всю строку, превращается в пустую строку.
     */
    public function testStripSingleLineCommentAtStart(): void
    {
        $result = CommentsHelper::stripComments("// full line comment\ncode");
        $this->assertSame("\ncode", $result);
    }

    /**
     * Однострочный блочный комментарий на одной строке удаляется,
     * текст до и после него объединяется.
     */
    public function testStripMultiLineComment(): void
    {
        $result = CommentsHelper::stripComments("before /* comment */ after");
        $this->assertSame('before  after', $result);
    }

    /**
     * Блочный комментарий, занимающий несколько строк, удаляется целиком.
     */
    public function testStripMultiLineCommentSpanningLines(): void
    {
        $result = CommentsHelper::stripComments("before\n/* line1\nline2 */\nafter");
        $this->assertSame("before\n\nafter", $result);
    }

    /**
     * Смешанный случай: и однострочный, и блочный комментарии в одном тексте.
     */
    public function testStripMixedComments(): void
    {
        $input = "line1 // comment\nline2 /* block */ rest\nline3";
        $result = CommentsHelper::stripComments($input);
        $this->assertSame("line1\nline2  rest\nline3", $result);
    }

    /**
     * Текст, состоящий только из однострочного комментария, — результат пуст.
     */
    public function testStripCommentsOnlyComments(): void
    {
        $result = CommentsHelper::stripComments("// only a comment");
        $this->assertSame('', $result);
    }

    /**
     * Текст, состоящий только из блочного комментария, — результат пуст.
     */
    public function testStripCommentsOnlyBlockComment(): void
    {
        $result = CommentsHelper::stripComments("/* only block */");
        $this->assertSame('', $result);
    }

    /**
     * Хвостовые пробелы, оставшиеся после удаления комментария, тоже удаляются.
     */
    public function testStripCommentsTrailingSpacesRemoved(): void
    {
        $result = CommentsHelper::stripComments("code   // comment");
        $this->assertSame('code', $result);
    }

    /**
     * Хвостовые переводы строк в конце всего текста удаляются.
     */
    public function testStripCommentsTrailingNewlinesRemoved(): void
    {
        $result = CommentsHelper::stripComments("code\n\n\n");
        $this->assertSame('code', $result);
    }

    /**
     * Несколько блочных комментариев на одной строке — все удаляются.
     */
    public function testStripCommentsMultipleBlockComments(): void
    {
        $result = CommentsHelper::stripComments("a /* b */ c /* d */ e");
        $this->assertSame('a  c  e', $result);
    }

    /**
     * Одиночный слэш (http:/...) не является комментарием и сохраняется.
     */
    public function testStripCommentsPreservesNonCommentSlashes(): void
    {
        $result = CommentsHelper::stripComments("http:/example.com");
        $this->assertSame('http:/example.com', $result);
    }

    /**
     * Двойной слэш в URL (http://) распознаётся как начало комментария —
     * текст после «//» удаляется. Это известное ограничение.
     */
    public function testStripCommentsDoubleSlashInUrl(): void
    {
        $result = CommentsHelper::stripComments("url: http://example.com");
        $this->assertSame('url: http:', $result);
    }
}
