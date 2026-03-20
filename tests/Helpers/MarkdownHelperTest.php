<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\dto\tools\MarkdownChunksResultDto;
use app\modules\neuron\helpers\MarkdownChunckHelper;
use app\modules\neuron\helpers\MarkdownHelper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Проверяет семантическое разбиение markdown в наборе разнообразных сценариев.
     *
     * @param string $markdown       Исходный markdown.
     * @param int    $targetChars    Целевой размер чанка.
     * @param int    $expectedChunks Ожидаемое количество чанков.
     * @param bool   $expectOversize Ожидается ли oversized чанк.
     */
    #[DataProvider('chunkBySemanticBlocksDatasetProvider')]
    public function testChunkBySemanticBlocksDataset(
        string $markdown,
        int $targetChars,
        int $expectedChunks,
        bool $expectOversize,
    ): void {
        $result = MarkdownChunckHelper::chunkBySemanticBlocks($markdown, $targetChars);

        $this->assertInstanceOf(MarkdownChunksResultDto::class, $result);
        $this->assertSame($targetChars, $result->targetChars);
        $this->assertCount($expectedChunks, $result->chunks);

        $hasOversized = false;
        foreach ($result->chunks as $chunk) {
            $this->assertNotSame('', $chunk->text);
            $this->assertGreaterThan(0, $chunk->lengthChars);
            $this->assertSame(mb_strlen($chunk->text), $chunk->lengthChars);
            if ($chunk->isOversized) {
                $hasOversized = true;
            }
        }

        $this->assertSame($expectOversize, $hasOversized);
    }

    /**
     * Возвращает набор данных для проверки разных форматов markdown.
     *
     * @return array<string, array{markdown: string, targetChars: int, expectedChunks: int, expectOversize: bool}>
     */
    public static function chunkBySemanticBlocksDatasetProvider(): array
    {
        return [
            // 1. Один короткий абзац.
            'single short paragraph' => [
                'markdown' => 'Короткий абзац без разбиения.',
                'targetChars' => 200,
                'expectedChunks' => 1,
                'expectOversize' => false,
            ],
            // 2. Два абзаца, делятся на два чанка.
            'two paragraphs split' => [
                'markdown' => "Первый абзац с текстом для проверки.\n\nВторой абзац с независимым смыслом.",
                'targetChars' => 35,
                'expectedChunks' => 2,
                'expectOversize' => true,
            ],
            // 3. Таблица должна остаться целой.
            'table remains whole' => [
                'markdown' => "| A | B |\n|---|---|\n| 1 | 2 |\n| 3 | 4 |\n\nПосле таблицы текст.",
                'targetChars' => 30,
                'expectedChunks' => 2,
                'expectOversize' => true,
            ],
            // 4. Кодовый fenced-блок не должен дробиться.
            'fenced code remains whole' => [
                'markdown' => "```php\n\$a = 1;\n\$b = 2;\necho \$a + \$b;\n```\n\nТекст после кода.",
                'targetChars' => 20,
                'expectedChunks' => 2,
                'expectOversize' => true,
            ],
            // 5. Длинный абзац делится по предложениям.
            'long paragraph split by sentences' => [
                'markdown' => 'Первое предложение достаточно длинное для теста.'
                    . ' Второе предложение тоже длинное и независимое.'
                    . ' Третье предложение завершает пример.',
                'targetChars' => 70,
                'expectedChunks' => 3,
                'expectOversize' => false,
            ],
            // 6. Список обрабатывается как единый логический блок.
            'list block' => [
                'markdown' => "- Первый пункт списка.\n- Второй пункт списка.\n- Третий пункт списка.",
                'targetChars' => 200,
                'expectedChunks' => 1,
                'expectOversize' => false,
            ],
            // 7. Заголовок и текст после него.
            'heading and paragraphs' => [
                'markdown' => "# Раздел\n\nАбзац один.\n\nАбзац два.",
                'targetChars' => 18,
                'expectedChunks' => 2,
                'expectOversize' => true,
            ],
            // 8. Пустой markdown.
            'empty markdown' => [
                'markdown' => '',
                'targetChars' => 100,
                'expectedChunks' => 0,
                'expectOversize' => false,
            ],
            // 9. Один огромный неделимый кусок (без пунктуации) остаётся oversized.
            'oversized no sentence boundaries' => [
                'markdown' => 'Сверхдлинныйтекстбеззнаковпрепинания'
                    . 'иконцовпредложенийкоторыйневозможноразделитьсемантически',
                'targetChars' => 20,
                'expectedChunks' => 1,
                'expectOversize' => true,
            ],
            // 10. Смешанный markdown.
            'mixed blocks markdown' => [
                'markdown' => "## Заголовок\n\nАбзац перед таблицей.\n\n| X | Y |\n|---|---|\n| 1 | 9 |"
                    . "\n\nФинальный абзац.",
                'targetChars' => 45,
                'expectedChunks' => 3,
                'expectOversize' => false,
            ],
            // 11. Абзац перед таблицей должен быть в том же чанке.
            'paragraph before table same chunk' => [
                'markdown' => "Текст перед таблицей должен идти вместе с таблицей.\n\n| A | B |\n|---|---|\n| 1 | 2 |",
                'targetChars' => 25,
                'expectedChunks' => 1,
                'expectOversize' => true,
            ],
            // 12. Заголовок и следующий абзац должны быть в одном чанке.
            'heading with paragraph same chunk' => [
                'markdown' => "# Заголовок\n\nТекст абзаца сразу после заголовка.",
                'targetChars' => 10,
                'expectedChunks' => 1,
                'expectOversize' => true,
            ],
            // 13. Заголовок перед таблицей должен быть в одном чанке с таблицей.
            'heading with table same chunk' => [
                'markdown' => "## Таблица\n\n| A | B |\n|---|---|\n| 1 | 2 |",
                'targetChars' => 10,
                'expectedChunks' => 1,
                'expectOversize' => true,
            ],
        ];
    }

    /**
     * Проверяет, что некорректный targetChars приводит к исключению.
     */
    public function testChunkBySemanticBlocksThrowsForInvalidTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MarkdownChunckHelper::chunkBySemanticBlocks('text', 0);
    }

    /**
     * Проверяет корректность сериализации результирующего DTO.
     */
    public function testChunkBySemanticBlocksToArrayStructure(): void
    {
        $result = MarkdownChunckHelper::chunkBySemanticBlocks(
            "Абзац один.\n\nАбзац два.",
            20,
        );

        $array = $result->toArray();
        $this->assertArrayHasKey('targetChars', $array);
        $this->assertArrayHasKey('totalChunks', $array);
        $this->assertArrayHasKey('totalChars', $array);
        $this->assertArrayHasKey('chunks', $array);
    }
}
