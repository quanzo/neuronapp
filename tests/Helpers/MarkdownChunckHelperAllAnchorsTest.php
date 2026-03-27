<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\dto\tools\MarkdownChunksResultDto;
use app\modules\neuron\helpers\MarkdownChunckHelper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see MarkdownChunckHelper::chunksAroundAllAnchorLineRegex()}.
 *
 * Проверяют:
 * - возврат списка чанков для нескольких вхождений
 * - непересечение чанков по семантическим блокам
 * - ограничение maxCharsPerBlock и oversized-поведение
 * - ограничение maxTotalChars (остановка добавления)
 * - обработку отсутствия совпадений и некорректных параметров
 */
final class MarkdownChunckHelperAllAnchorsTest extends TestCase
{
    #[DataProvider('datasetProvider')]
    public function testChunksAroundAllAnchorLineRegexDataset(
        string $markdown,
        string|array $regex,
        int $perBlock,
        int $totalMax,
        int $expectedChunks,
        ?string $mustContain,
    ): void {
        $result = MarkdownChunckHelper::chunksAroundAllAnchorLineRegex($markdown, $regex, $perBlock, $totalMax);

        $this->assertInstanceOf(MarkdownChunksResultDto::class, $result);
        $this->assertSame($perBlock, $result->targetChars);
        $this->assertCount($expectedChunks, $result->chunks);

        $sum = 0;
        foreach ($result->chunks as $chunk) {
            $this->assertSame(mb_strlen($chunk->text), $chunk->lengthChars);
            $sum += $chunk->lengthChars;
        }
        $this->assertLessThanOrEqual($totalMax, $sum);

        if ($mustContain !== null) {
            $joined = implode("\n\n---\n\n", array_map(static fn($c) => $c->text, $result->chunks));
            $this->assertStringContainsString($mustContain, $joined);
        }
    }

    /**
     * @return array<string, array{
     *   markdown:string,
     *   regex:string|array,
     *   perBlock:int,
     *   totalMax:int,
     *   expectedChunks:int,
     *   mustContain:?string
     * }>
     */
    public static function datasetProvider(): array
    {
        $mdTwoAdjacentMatches = implode("\n", [
            'Paragraph A line 1',
            'FIND_ME',
            '',
            'Paragraph B line 1',
            'FIND_ME',
            '',
            'Tail.',
        ]);

        $mdFarMatches = implode("\n", [
            '# Title',
            '',
            'Intro.',
            '',
            'Anchor: FIND_ME',
            '',
            str_repeat('middle ', 80),
            '',
            'Another place:',
            'FIND_ME',
            '',
            'Tail.',
        ]);

        return [
            // 1. Нет совпадений.
            'no matches' => [
                'markdown' => "a\nb\nc",
                'regex' => '/FIND_ME/u',
                'perBlock' => 200,
                'totalMax' => 5000,
                'expectedChunks' => 0,
                'mustContain' => null,
            ],
            // 1b. Поиск по обычной строке (не regex).
            'plain string pattern finds matches' => [
                'markdown' => $mdFarMatches,
                'regex' => 'FIND_ME',
                'perBlock' => 200,
                'totalMax' => 5000,
                'expectedChunks' => 2,
                'mustContain' => 'Another place:',
            ],
            // 1c. Поиск по массиву паттернов (строка + regex).
            'array patterns find matches' => [
                'markdown' => $mdFarMatches,
                'regex' => ['NOT_PRESENT', '/FIND_ME/u'],
                'perBlock' => 200,
                'totalMax' => 5000,
                'expectedChunks' => 2,
                'mustContain' => 'Another place:',
            ],
            // 2. Два далёких совпадения -> два чанка.
            'two far anchors produce two chunks' => [
                'markdown' => $mdFarMatches,
                'regex' => '/FIND_ME/u',
                'perBlock' => 120,
                'totalMax' => 5000,
                'expectedChunks' => 2,
                'mustContain' => 'Another place:',
            ],
            // 3. Смежные абзацы с совпадением. Окно первого включает оба абзаца -> второй чанк пропускаем.
            'adjacent matches do not create overlapping second chunk' => [
                'markdown' => $mdTwoAdjacentMatches,
                'regex' => '/FIND_ME/u',
                'perBlock' => 500,
                'totalMax' => 5000,
                'expectedChunks' => 1,
                'mustContain' => 'Paragraph B line 1',
            ],
            // 4. Ограничение по totalMax останавливает добавление чанков.
            'total max stops adding chunks' => [
                'markdown' => $mdFarMatches . "\n\n" . $mdFarMatches,
                'regex' => '/FIND_ME/u',
                'perBlock' => 200,
                'totalMax' => 250,
                'expectedChunks' => 3,
                'mustContain' => 'Anchor: FIND_ME',
            ],
            // 5. Oversized: якорь внутри огромного fenced-блока -> чанк oversized и может превышать perBlock.
            'oversized anchor block returned as is' => [
                'markdown' => implode("\n", [
                    '```',
                    str_repeat('A', 2000),
                    'FIND_ME',
                    str_repeat('B', 2000),
                    '```',
                    '',
                    'FIND_ME',
                ]),
                'regex' => '/FIND_ME/u',
                'perBlock' => 100,
                'totalMax' => 20000,
                'expectedChunks' => 2,
                'mustContain' => '```',
            ],
            // 6. Фразовый (не regex) запрос должен находить оба русских вхождения.
            'phrase query finds russian anchors' => [
                'markdown' => implode("\n", [
                    'Первый блок: коэффициент локализации и производимая продукция, расчеты и результаты.',
                    '',
                    str_repeat('длинный_промежуточный_текст ', 80),
                    '',
                    'Второй блок: коэффициент локализации производимая продукция; отдельные расчеты, итоговые результаты.',
                ]),
                'regex' => 'коэффициент локализация производимая продукция расчеты результаты',
                'perBlock' => 200,
                'totalMax' => 2000,
                'expectedChunks' => 2,
                'mustContain' => 'Второй блок:',
            ],
            // 7. Control-байты внутри "связки слов" не матчатся разделителем [\s\pP]+ -> совпадений нет.
            'phrase query with control chars has no matches' => [
                'markdown' => implode("\n", [
                    "Блок 1: коэффициент\x00 локализация ### производимая продукция, расчеты результаты.",
                    '',
                    str_repeat('промежуток ', 90),
                    '',
                    "Блок 2: коэффициент\x07 локализация $$$ производимая продукция; расчеты, результаты.",
                ]),
                'regex' => 'коэффициент локализация производимая продукция расчеты результаты',
                'perBlock' => 220,
                'totalMax' => 2000,
                'expectedChunks' => 0,
                'mustContain' => null,
            ],
            // 8. Печатные спецсимволы и пунктуация не мешают phrase-поиску по нескольким якорям.
            'phrase query with punctuation finds multiple anchors' => [
                'markdown' => implode("\n", [
                    "noise\x00@@@\nБлок 1: коэффициент локализация производимая продукция расчеты результаты.",
                    '',
                    str_repeat('промежуток ', 120),
                    '',
                    "Блок 2: коэффициент локализация производимая продукция расчеты результаты.\x07",
                ]),
                'regex' => 'коэффициент локализация производимая продукция расчеты результаты',
                'perBlock' => 200,
                'totalMax' => 2000,
                'expectedChunks' => 2,
                'mustContain' => 'Блок 2:',
            ],
        ];
    }

    #[DataProvider('invalidParamsProvider')]
    public function testChunksAroundAllAnchorLineRegexThrowsOnInvalidParams(
        string|array $regex,
        int $perBlock,
        int $totalMax,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        MarkdownChunckHelper::chunksAroundAllAnchorLineRegex("text\nline2", $regex, $perBlock, $totalMax);
    }

    /**
     * @return array<string, array{regex:string|array, perBlock:int, totalMax:int}>
     */
    public static function invalidParamsProvider(): array
    {
        return [
            'empty regex' => ['regex' => '', 'perBlock' => 10, 'totalMax' => 100],
            // Невалидный regex, который выглядит как regex — должен быть ошибкой.
            'invalid regex' => ['regex' => '/(/u', 'perBlock' => 10, 'totalMax' => 100],
            'perBlock <= 0' => ['regex' => '/x/u', 'perBlock' => 0, 'totalMax' => 100],
            'totalMax <= 0' => ['regex' => '/x/u', 'perBlock' => 10, 'totalMax' => 0],
            'empty patterns array' => ['regex' => [], 'perBlock' => 10, 'totalMax' => 100],
        ];
    }
}
