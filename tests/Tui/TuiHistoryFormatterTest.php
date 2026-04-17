<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\tui\render\TuiHistoryFormatter;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryEntryDto;
use app\modules\neuron\classes\dto\tui\view\TuiThemeDto;
use app\modules\neuron\classes\dto\tui\view\blocks\CodeBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\HeadingBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\ListBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\PanelBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\TableBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\TextBlockDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see TuiHistoryFormatter}.
 */
final class TuiHistoryFormatterTest extends TestCase
{
    /**
     * Базовый сценарий: Panel + Table + List форматируются в строки.
     */
    public function testFormatsPanelWithBlocks(): void
    {
        // Проверяем, что форматтер не падает и возвращает непустой вывод.
        $history = (new TuiHistoryDto())->append(
            TuiHistoryEntryDto::output('demo')->setBlocks([
                new PanelBlockDto('Workspace', [
                    new TextBlockDto('Hello'),
                    new TableBlockDto(['A', 'B'], [['1', '2']]),
                    new ListBlockDto(['x', 'y']),
                ]),
            ]),
        );

        $lines = (new TuiHistoryFormatter())->toDisplayLines($history, 40, new TuiThemeDto());
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('Workspace', implode("\n", $lines));
    }

    /**
     * Набор граничных сценариев форматирования (минимум 10 наборов данных).
     */
    #[DataProvider('formattingCases')]
    public function testFormattingCases(int $innerWidth, TuiHistoryEntryDto $entry, callable $assert): void
    {
        // Тест покрывает разные ширины и типы блоков, включая «неудобные» значения.
        $history = (new TuiHistoryDto())->append($entry);
        $lines = (new TuiHistoryFormatter())->toDisplayLines($history, $innerWidth, new TuiThemeDto());
        $assert($lines);
    }

    /**
     * @return array<string, array{0:int,1:TuiHistoryEntryDto,2:callable}>
     */
    public static function formattingCases(): array
    {
        return [
            'heading_underline_small_width' => [
                10,
                TuiHistoryEntryDto::output('h')->setBlocks([new HeadingBlockDto('Workspace')]),
                static function (array $lines): void {
                    self::assertNotEmpty($lines);
                },
            ],
            'text_wrap' => [
                8,
                TuiHistoryEntryDto::output('t')->setBlocks([new TextBlockDto('123456789')]),
                static function (array $lines): void {
                    self::assertGreaterThan(1, count($lines));
                },
            ],
            'table_ellipsis' => [
                12,
                TuiHistoryEntryDto::output('tbl')->setBlocks([
                    new TableBlockDto(['Name', 'Type'], [['very-long-name', 'dir']]),
                ]),
                static function (array $lines): void {
                    self::assertNotEmpty($lines);
                },
            ],
            'code_with_language' => [
                20,
                TuiHistoryEntryDto::output('code')->setBlocks([
                    (new CodeBlockDto("echo 1;\nexit;"))->setLanguage('php'),
                ]),
                static function (array $lines): void {
                    self::assertStringContainsString('[php]', implode("\n", $lines));
                },
            ],
            'code_line_numbers' => [
                20,
                TuiHistoryEntryDto::output('code')->setBlocks([
                    (new CodeBlockDto("a\nb\nc"))->setLineNumbers(true),
                ]),
                static function (array $lines): void {
                    self::assertStringContainsString('1', implode("\n", $lines));
                },
            ],
            'panel_nested' => [
                30,
                TuiHistoryEntryDto::output('p')->setBlocks([
                    new PanelBlockDto('P', [new TextBlockDto('X')]),
                ]),
                static function (array $lines): void {
                    self::assertStringContainsString('P', implode("\n", $lines));
                },
            ],
            'list_basic' => [
                10,
                TuiHistoryEntryDto::output('l')->setBlocks([new ListBlockDto(['a', 'b'])]),
                static function (array $lines): void {
                    self::assertStringContainsString('a', implode("\n", $lines));
                },
            ],
            'inner_width_zero' => [
                0,
                TuiHistoryEntryDto::output('z')->setBlocks([new TextBlockDto('abc')]),
                static function (array $lines): void {
                    // При width=0 форматтер обязан вернуть строки (в худшем случае пустые/обрезанные), но не падать.
                    self::assertIsArray($lines);
                },
            ],
            'unsupported_block_type' => [
                20,
                (static function (): TuiHistoryEntryDto {
                    // Негативный кейс: блок с неизвестным type.
                    $b = new class implements \app\modules\neuron\interfaces\tui\view\TuiBlockInterface {
                        public function getType(): string
                        {
                            return 'unknown';
                        }
                    };
                    return TuiHistoryEntryDto::output('u')->setBlocks([$b]);
                })(),
                static function (array $lines): void {
                    self::assertStringContainsString('unsupported', implode("\n", $lines));
                },
            ],
            'multiline_text' => [
                20,
                TuiHistoryEntryDto::output('m')->setBlocks([new TextBlockDto("a\nb")]),
                static function (array $lines): void {
                    self::assertStringContainsString("a\nb", implode("\n", $lines));
                },
            ],
        ];
    }
}
