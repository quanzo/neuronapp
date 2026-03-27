<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\dto\tools\MarkdownChunkDto;
use app\modules\neuron\helpers\MarkdownChunckHelper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see MarkdownChunckHelper::chunkAroundAnchorLineRegex()}.
 *
 * Проверяют, что helper:
 * - находит якорную строку по regex начиная с заданного смещения;
 * - возвращает окно семантических блоков вокруг якоря с лимитом по символам;
 * - не разрывает семантические блоки;
 * - корректно ведёт себя, если якорный блок больше ограничения;
 * - корректно обрабатывает ошибки входных параметров и отсутствие совпадений.
 */
final class MarkdownChunckHelperAnchorWindowTest extends TestCase
{
    /**
     * Проверяет набор сценариев поиска якоря и построения окна вокруг него.
     *
     * @param string $markdown
     * @param int    $fromChar
     * @param string $regex
     * @param int    $maxChars
     * @param bool   $expectFound
     * @param bool   $expectOversized
     * @param string|null $mustContain
     * @param string|null $mustNotContain
     */
    #[DataProvider('anchorWindowDatasetProvider')]
    public function testChunkAroundAnchorLineRegexDataset(
        string $markdown,
        int $fromChar,
        string|array $regex,
        int $maxChars,
        bool $expectFound,
        bool $expectOversized,
        ?string $mustContain,
        ?string $mustNotContain,
    ): void {
        $chunk = MarkdownChunckHelper::chunkAroundAnchorLineRegex($markdown, $fromChar, $regex, $maxChars);

        if (!$expectFound) {
            $this->assertNull($chunk);
            return;
        }

        $this->assertInstanceOf(MarkdownChunkDto::class, $chunk);
        $this->assertNotSame('', $chunk->text);
        $this->assertSame(mb_strlen($chunk->text), $chunk->lengthChars);
        $this->assertSame($expectOversized, $chunk->isOversized);

        if (!$expectOversized) {
            $this->assertLessThanOrEqual($maxChars, $chunk->lengthChars);
        }

        if ($mustContain !== null) {
            $this->assertStringContainsString($mustContain, $chunk->text);
        }
        if ($mustNotContain !== null) {
            $this->assertStringNotContainsString($mustNotContain, $chunk->text);
        }
    }

    /**
     * Набор данных для тестирования поведения вокруг якоря.
     *
     * Минимум 10 сценариев, включая граничные и заведомо "не найдено".
     *
     * @return array<string, array{
     *   markdown:string,
     *   fromChar:int,
     *   regex:string,
     *   maxChars:int,
     *   expectFound:bool,
     *   expectOversized:bool,
     *   mustContain:?string,
     *   mustNotContain:?string
     * }>
     */
    public static function anchorWindowDatasetProvider(): array
    {
        $md = implode("\n", [
            '# H1',
            '',
            'Intro paragraph line.',
            '',
            '## H2',
            '',
            '- item 1',
            '- item 2',
            '',
            'Anchor: FIND_ME',
            'Second anchor line for context.',
            '',
            '| A | B |',
            '|---|---|',
            '| 1 | 2 |',
            '',
            'Tail paragraph.',
        ]);

        return [
            // 1. Якорь в обычном абзаце: должен быть найден.
            'anchor in paragraph' => [
                'markdown' => $md,
                'fromChar' => 0,
                'regex' => '/FIND_ME/u',
                'maxChars' => 200,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => 'Anchor: FIND_ME',
                'mustNotContain' => null,
            ],
            // 1b. Поиск по обычной строке (не regex): должен быть найден.
            'anchor by plain string' => [
                'markdown' => $md,
                'fromChar' => 0,
                'regex' => 'FIND_ME',
                'maxChars' => 200,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => 'Anchor: FIND_ME',
                'mustNotContain' => null,
            ],
            // 1c. Поиск по массиву паттернов: строка + regex.
            'anchor by array of patterns' => [
                'markdown' => $md,
                'fromChar' => 0,
                'regex' => ['NOT_PRESENT', '/FIND_ME/u'],
                'maxChars' => 200,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => 'Anchor: FIND_ME',
                'mustNotContain' => null,
            ],
            // 2. Поиск с fromChar, пропускающий первую часть: якорь всё равно находится.
            'anchor found after offset' => [
                'markdown' => $md,
                'fromChar' => 30,
                'regex' => '/FIND_ME/u',
                'maxChars' => 200,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => 'Anchor: FIND_ME',
                'mustNotContain' => null,
            ],
            // 3. Поиск не должен находить совпадение до fromChar.
            'anchor not found when offset after anchor' => [
                'markdown' => $md,
                'fromChar' => 10000,
                'regex' => '/FIND_ME/u',
                'maxChars' => 200,
                'expectFound' => false,
                'expectOversized' => false,
                'mustContain' => null,
                'mustNotContain' => null,
            ],
            // 4. Regex якоря по началу строки.
            'anchor by line start' => [
                'markdown' => $md,
                'fromChar' => 0,
                'regex' => '/^Anchor:/u',
                'maxChars' => 120,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => 'Anchor: FIND_ME',
                'mustNotContain' => null,
            ],
            // 5. Якорь в таблице: блок должен оставаться целым (таблица целиком).
            'anchor inside table' => [
                'markdown' => implode("\n", [
                    'Before.',
                    '',
                    '| X | Y |',
                    '|---|---|',
                    '| FIND_ME | 2 |',
                    '| 3 | 4 |',
                    '',
                    'After.',
                ]),
                'fromChar' => 0,
                'regex' => '/FIND_ME/u',
                'maxChars' => 120,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => '| FIND_ME | 2 |',
                'mustNotContain' => null,
            ],
            // 6. Якорь внутри fenced-кода: fenced-блок целиком.
            'anchor inside fenced code' => [
                'markdown' => implode("\n", [
                    'Before.',
                    '```php',
                    '$x = 1;',
                    '// FIND_ME',
                    '$y = 2;',
                    '```',
                    'After.',
                ]),
                'fromChar' => 0,
                'regex' => '/FIND_ME/u',
                'maxChars' => 200,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => '```php',
                'mustNotContain' => null,
            ],
            // 7. Якорь не найден: метод возвращает null.
            'no match returns null' => [
                'markdown' => $md,
                'fromChar' => 0,
                'regex' => '/NOT_PRESENT/u',
                'maxChars' => 200,
                'expectFound' => false,
                'expectOversized' => false,
                'mustContain' => null,
                'mustNotContain' => null,
            ],
            // 8. Якорный блок больше maxChars: должен вернуться целиком и быть oversized.
            'anchor block larger than limit returns whole block' => [
                'markdown' => implode("\n", [
                    '```',
                    str_repeat('A', 2000),
                    'FIND_ME',
                    str_repeat('B', 2000),
                    '```',
                ]),
                'fromChar' => 0,
                'regex' => '/FIND_ME/u',
                'maxChars' => 100,
                'expectFound' => true,
                'expectOversized' => true,
                'mustContain' => 'FIND_ME',
                'mustNotContain' => null,
            ],
            // 9. Якорь в заголовке+абзаце (heading_with_paragraph), блок не должен рваться.
            'anchor in heading with paragraph merged block' => [
                'markdown' => implode("\n", [
                    '# Title',
                    '',
                    'Paragraph with FIND_ME inside.',
                    '',
                    'Next paragraph.',
                ]),
                'fromChar' => 0,
                'regex' => '/FIND_ME/u',
                'maxChars' => 200,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => '# Title',
                'mustNotContain' => null,
            ],
            // 10. Проверка лимита: при малом maxChars якорный блок может вернуться oversized (блоки не делим).
            'small maxChars truncates by blocks' => [
                'markdown' => $md,
                'fromChar' => 0,
                'regex' => '/FIND_ME/u',
                'maxChars' => 60,
                'expectFound' => true,
                'expectOversized' => true,
                'mustContain' => 'FIND_ME',
                'mustNotContain' => 'Tail paragraph.',
            ],
            // 11. Фраза (не regex) должна находить русскоязычный якорь после phrase->regex преобразования.
            'phrase query in russian paragraph' => [
                'markdown' => implode("\n", [
                    'Вводный блок.',
                    '',
                    'Отчет: коэффициент локализации производимая продукция расчеты результаты за период.',
                    '',
                    'Хвостовой блок.',
                ]),
                'fromChar' => 0,
                'regex' => 'коэффициент локализация производимая продукция расчеты результаты',
                'maxChars' => 300,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => 'коэффициент локализации',
                'mustNotContain' => null,
            ],
            // 12. Фразовый запрос должен проходить через пунктуацию и до 5 слов между якорями.
            'phrase query tolerates punctuation and gaps' => [
                'markdown' => implode("\n", [
                    'prefix',
                    '',
                    'Коэффициент, налоговой локализации и прочей производимой товарной продукции: финальные расчеты, а также результаты.',
                    '',
                    'suffix',
                ]),
                'fromChar' => 0,
                'regex' => 'коэффициент локализация производимая продукция расчеты результаты',
                'maxChars' => 400,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => 'финальные расчеты',
                'mustNotContain' => null,
            ],
            // 13. Control-байты между словами не матчатся разделителем [\s\pP]+ -> якорь не находится.
            'phrase query with control chars is not found' => [
                'markdown' => implode("\n", [
                    'prefix',
                    '',
                    "коэффициент\x00\x01 локализация ### производимая $$$ продукция\tрасчеты\x07 результаты",
                    '',
                    'suffix',
                ]),
                'fromChar' => 0,
                'regex' => 'коэффициент локализация производимая продукция расчеты результаты',
                'maxChars' => 400,
                'expectFound' => false,
                'expectOversized' => false,
                'mustContain' => null,
                'mustNotContain' => null,
            ],
            // 14. Непечатаемые/спецсимволы вне якорной фразы не должны мешать поиску.
            'phrase query with punctuation noise is found' => [
                'markdown' => implode("\n", [
                    "prefix\x00\x01@@@",
                    '',
                    'коэффициент локализация производимая продукция расчеты результаты',
                    '',
                    "suffix###\x07",
                ]),
                'fromChar' => 0,
                'regex' => 'коэффициент локализация производимая продукция расчеты результаты',
                'maxChars' => 400,
                'expectFound' => true,
                'expectOversized' => false,
                'mustContain' => 'коэффициент локализация производимая продукция',
                'mustNotContain' => null,
            ],
        ];
    }

    /**
     * Проверяет, что некорректные параметры приводят к исключениям.
     */
    #[DataProvider('invalidParamsProvider')]
    public function testChunkAroundAnchorLineRegexThrowsOnInvalidParams(
        int $fromChar,
        string|array $regex,
        int $maxChars,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        MarkdownChunckHelper::chunkAroundAnchorLineRegex("text\nline2", $fromChar, $regex, $maxChars);
    }

    /**
     * Набор заведомо некорректных входных параметров.
     *
     * @return array<string, array{fromChar:int, regex:string|array, maxChars:int}>
     */
    public static function invalidParamsProvider(): array
    {
        return [
            // 1. fromChar отрицательный.
            'negative fromChar' => ['fromChar' => -1, 'regex' => '/x/u', 'maxChars' => 10],
            // 2. maxChars некорректный.
            'non positive maxChars' => ['fromChar' => 0, 'regex' => '/x/u', 'maxChars' => 0],
            // 3. regex пустой.
            'empty regex' => ['fromChar' => 0, 'regex' => '', 'maxChars' => 10],
            // 4. Невалидный regex, который выглядит как regex — должен быть ошибкой.
            'invalid regex syntax' => ['fromChar' => 0, 'regex' => '/(/u', 'maxChars' => 10],
            // 5. Пустой массив паттернов.
            'empty patterns array' => ['fromChar' => 0, 'regex' => [], 'maxChars' => 10],
        ];
    }
}
