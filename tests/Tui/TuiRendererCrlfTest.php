<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\tui\render\TuiRenderer;
use app\modules\neuron\classes\dto\tui\LayoutDto;
use app\modules\neuron\classes\dto\tui\TerminalSizeDto;
use app\modules\neuron\classes\dto\tui\TuiStateDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryEntryDto;
use app\modules\neuron\classes\status\StatusBar;
use PHPUnit\Framework\TestCase;

/**
 * Регрессионный тест: full render должен явно позиционировать курсор по X=1,
 * чтобы не было «съезда вправо» из-за особенностей обработки CR/LF.
 */
final class TuiRendererCrlfTest extends TestCase
{
    /**
     * Проверяем, что в выводе присутствует позиционирование в колонку 1.
     */
    public function testRenderFullPositionsCursorToColumnOne(): void
    {
        $history = (new TuiHistoryDto())->append(TuiHistoryEntryDto::output('x'));
        $state = (new TuiStateDto())->setHistory($history);
        $layout = new LayoutDto(
            outputContentStart: 2,
            outputContentEnd: 5,
            inputContentStart: 7,
            inputContentEnd: 9,
            statusLine: 10,
        );
        $size = new TerminalSizeDto(20, 10);
        $statusBar = new StatusBar();

        ob_start();
        (new TuiRenderer())->renderFull($state, $layout, $size, $statusBar);
        $out = (string) ob_get_clean();

        $this->assertStringContainsString("\033[1;1H", $out);
        $this->assertStringContainsString("\033[2;1H", $out);
    }
}
