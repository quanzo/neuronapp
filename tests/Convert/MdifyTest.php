<?php

declare(strict_types=1);

namespace Tests\Convert;

use app\modules\neuron\classes\convert\Mdify;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see Mdify}.
 *
 * Требование проекта: минимум 10 тестовых кейсов + граничные условия.
 * Здесь мы проверяем не «идеальный markdown», а стабильные инварианты:
 * - скрипты/стили не попадают в результат;
 * - code/blockquote/hr конвертируются предсказуемо;
 * - ссылки/картинки/таблицы/списки не разваливаются в пустоту;
 * - пустой вход обрабатывается корректно.
 */
final class MdifyTest extends TestCase
{
    /**
     * Граничный случай: пустой HTML должен вернуть пустую строку.
     */
    public function testEmptyHtmlReturnsEmptyString(): void
    {
        // Empty input should be safe and deterministic.
        $this->assertSame('', Mdify::htmlToMarkdown(''));
    }

    /**
     * Набор кейсов для htmlToMarkdown (минимум 10).
     *
     * @return array<string, array{html:string, contains:string[], notContains:string[]}>
     */
    public static function htmlToMarkdownCases(): array
    {
        return [
            // 1) Script should be removed completely.
            'scriptRemoved' => [
                'html' => '<p>Hello</p><script>alert("x")</script>',
                'contains' => ['Hello'],
                'notContains' => ['alert("x")'],
            ],
            // 2) Style should be removed completely.
            'styleRemoved' => [
                'html' => '<style>body{color:red}</style><p>Text</p>',
                'contains' => ['Text'],
                'notContains' => ['body{color:red}'],
            ],
            // 3) Inline code should become backticks.
            'inlineCode' => [
                'html' => '<p>Use <code>printf()</code> here</p>',
                'contains' => ['Use `printf()` here'],
                'notContains' => ['<code>'],
            ],
            // 4) Preformatted block should become fenced code.
            'preBlock' => [
                'html' => '<pre><code>line1' . "\n" . 'line2</code></pre>',
                'contains' => ["```text\nline1\nline2\n```"],
                'notContains' => ['<pre>', '<code>'],
            ],
            // 5) Blockquote should be prefixed with >.
            'blockquote' => [
                'html' => '<blockquote><p>Quote</p><p>Second</p></blockquote>',
                'contains' => ["> Quote\n> Second"],
                'notContains' => ['<blockquote>'],
            ],
            // 6) HR should become markdown rule.
            'horizontalRule' => [
                'html' => '<p>A</p><hr><p>B</p>',
                'contains' => ["A\n\n---\n\nB"],
                'notContains' => ['<hr'],
            ],
            // 7) Link should become markdown link.
            'link' => [
                'html' => '<a href="https://example.com">Example</a>',
                'contains' => ['[Example](https://example.com)'],
                'notContains' => ['<a '],
            ],
            // 8) Image should become markdown image.
            'image' => [
                'html' => '<img src="https://example.com/a.png" alt="A">',
                'contains' => ['![A](https://example.com/a.png)'],
                'notContains' => ['<img'],
            ],
            // 9) Table should be converted to pipe format.
            'table' => [
                'html' => '<table><tr><th>H</th><th>H2</th></tr><tr><td>A</td><td>B</td></tr></table>',
                'contains' => ['| H | H2 |', '| --- | --- |', '| A | B |'],
                'notContains' => ['<table'],
            ],
            // 10) Simple list should keep list markers.
            'unorderedList' => [
                'html' => '<ul><li>One</li><li>Two</li></ul>',
                'contains' => ['- One', '- Two'],
                'notContains' => ['<ul', '<li'],
            ],
            // 11) Entities should be decoded.
            'entitiesDecoded' => [
                'html' => '<p>Tom &amp; Jerry</p>',
                'contains' => ['Tom & Jerry'],
                'notContains' => ['&amp;'],
            ],
            // 12) Comments should not appear.
            'commentsRemoved' => [
                'html' => '<p>Ok</p><!-- secret -->',
                'contains' => ['Ok'],
                'notContains' => ['secret'],
            ],
        ];
    }

    /**
     * Проверяет набор инвариантов (минимум 10 кейсов).
     */
    #[DataProvider('htmlToMarkdownCases')]
    public function testHtmlToMarkdownCases(string $html, array $contains, array $notContains): void
    {
        // Convert once; the output must be stable enough to validate by contains/not-contains.
        $md = Mdify::htmlToMarkdown($html);

        foreach ($contains as $needle) {
            // Each case defines required fragments that must appear.
            $this->assertStringContainsString($needle, $md);
        }

        foreach ($notContains as $needle) {
            // Each case defines fragments that must not leak into output.
            $this->assertStringNotContainsString($needle, $md);
        }
    }
}
