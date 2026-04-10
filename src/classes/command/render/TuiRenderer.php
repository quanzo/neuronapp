<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command\render;

use app\modules\neuron\classes\dto\tui\LayoutDto;
use app\modules\neuron\classes\dto\tui\TerminalSizeDto;
use app\modules\neuron\classes\dto\tui\TuiStateDto;
use app\modules\neuron\classes\status\CursorPositionStatus;
use app\modules\neuron\classes\status\HistoryCountStatus;
use app\modules\neuron\classes\status\ModeStatus;
use app\modules\neuron\classes\status\StatusBar;
use app\modules\neuron\helpers\TuiTextHelper;

/**
 * Рендерер TUI: отвечает за отрисовку рамок, содержимого и курсора.
 *
 * Должен получать состояние и геометрию извне и не заниматься чтением ввода.
 *
 * Пример использования:
 *
 * ```php
 * $renderer = new TuiRenderer();
 * $state = $renderer->renderFull($state, $layout, $size, $statusBar);
 * ```
 */
final class TuiRenderer
{
    /** ANSI-цвета для рамок */
    private const COLOR_GREEN = "\033[92m";
    private const COLOR_GRAY  = "\033[90m";
    private const COLOR_RESET = "\033[0m";

    /**
     * Полная перерисовка всего экрана.
     *
     * @param TuiStateDto      $state
     * @param LayoutDto        $layout
     * @param TerminalSizeDto  $size
     * @param StatusBar        $statusBar
     * @return TuiStateDto
     */
    public function renderFull(TuiStateDto $state, LayoutDto $layout, TerminalSizeDto $size, StatusBar $statusBar): TuiStateDto
    {
        echo "\033[2J\033[H";

        $width = $size->getWidth();
        $this->drawOutputAreaFull($state, $layout, $width);
        $this->drawInputAreaFull($state, $layout, $width);

        $statusLine = $this->buildAndRenderStatusLine($state, $layout, $width, $statusBar);

        $state->setPrevInputLines($state->getInputLines());
        $state->setPrevStatusLine($statusLine);

        $this->positionCursor($state, $layout, $width);
        return $state;
    }

    /**
     * Частичная перерисовка: обновляет только изменившиеся элементы.
     *
     * @param TuiStateDto      $state
     * @param LayoutDto        $layout
     * @param TerminalSizeDto  $size
     * @param StatusBar        $statusBar
     * @return TuiStateDto
     */
    public function renderPartial(TuiStateDto $state, LayoutDto $layout, TerminalSizeDto $size, StatusBar $statusBar): TuiStateDto
    {
        $width = $size->getWidth();

        $statusLine = $this->buildStatusLineContent($state, $width, $statusBar);
        if ($statusLine !== $state->getPrevStatusLine()) {
            $this->drawStatusLine($layout, $statusLine, $width);
            $state->setPrevStatusLine($statusLine);
        }

        $prevInputLines = $state->getPrevInputLines();
        $inputLines = $state->getInputLines();
        for ($row = 0; $row < 3; $row++) {
            $prev = (string) ($prevInputLines[$row] ?? '');
            $curr = (string) ($inputLines[$row] ?? '');
            if ($curr !== $prev) {
                $absY = $layout->getInputContentStart() + $row;
                $this->drawInputLine($state, $absY, $curr, $width);
                $prevInputLines[$row] = $curr;
            }
        }
        $state->setPrevInputLines($prevInputLines);

        $this->positionCursor($state, $layout, $width);
        return $state;
    }

    /**
     * @param TuiStateDto $state
     * @param LayoutDto   $layout
     * @param int         $width
     * @return void
     */
    private function drawOutputAreaFull(TuiStateDto $state, LayoutDto $layout, int $width): void
    {
        $color = $state->getFocus() === TuiStateDto::FOCUS_OUTPUT ? self::COLOR_GREEN : self::COLOR_GRAY;
        $reset = self::COLOR_RESET;

        $hline = '─';
        $vline = '│';
        $tl = '┌';
        $tr = '┐';
        $bl = '└';
        $br = '┘';
        $innerWidth = max(0, $width - 2);

        echo $color . $tl . str_repeat($hline, $innerWidth) . $tr . $reset . "\n";

        $displayLines = TuiTextHelper::buildDisplayLines($state->getHistory(), $innerWidth);
        $totalLines = count($displayLines);
        $visibleLines = $layout->getOutputVisibleLines();

        $maxScroll = max(0, $totalLines - $visibleLines);
        $outputScroll = min($state->getOutputScroll(), $maxScroll);
        $state->setOutputScroll($outputScroll);

        $startIdx = $outputScroll;
        $endIdx = min($startIdx + $visibleLines, $totalLines);

        for ($i = $startIdx; $i < $endIdx; $i++) {
            $line = (string) $displayLines[$i];
            $display = mb_strimwidth($line, 0, $innerWidth, '', 'UTF-8');
            echo $color . $vline . $reset
                . TuiTextHelper::padString($display, $innerWidth)
                . $color . $vline . $reset . "\n";
        }

        for ($i = $endIdx - $startIdx; $i < $visibleLines; $i++) {
            echo $color . $vline . $reset . str_repeat(' ', $innerWidth) . $color . $vline . $reset . "\n";
        }

        echo $color . $bl . str_repeat($hline, $innerWidth) . $br . $reset . "\n";
    }

    /**
     * @param TuiStateDto $state
     * @param LayoutDto   $layout
     * @param int         $width
     * @return void
     */
    private function drawInputAreaFull(TuiStateDto $state, LayoutDto $layout, int $width): void
    {
        $color = $state->getFocus() === TuiStateDto::FOCUS_INPUT ? self::COLOR_GREEN : self::COLOR_GRAY;
        $reset = self::COLOR_RESET;

        $hline = '─';
        $vline = '│';
        $tl = '┌';
        $tr = '┐';
        $bl = '└';
        $br = '┘';
        $innerWidth = max(0, $width - 2);

        echo $color . $tl . str_repeat($hline, $innerWidth) . $tr . $reset . "\n";

        $inputLines = $state->getInputLines();
        for ($row = 0; $row < 3; $row++) {
            $content = (string) ($inputLines[$row] ?? '');
            $display = mb_strimwidth($content, 0, $innerWidth, '', 'UTF-8');
            echo $color . $vline . $reset
                . TuiTextHelper::padString($display, $innerWidth)
                . $color . $vline . $reset . "\n";
        }

        echo $color . $bl . str_repeat($hline, $innerWidth) . $br . $reset . "\n";
    }

    /**
     * Рисует одну строку внутри области ввода (без рамок, только содержимое).
     *
     * @param TuiStateDto $state
     * @param int         $y
     * @param string      $content
     * @param int         $width
     * @return void
     */
    private function drawInputLine(TuiStateDto $state, int $y, string $content, int $width): void
    {
        $color = $state->getFocus() === TuiStateDto::FOCUS_INPUT ? self::COLOR_GREEN : self::COLOR_GRAY;
        $reset = self::COLOR_RESET;
        $innerWidth = max(0, $width - 2);
        $display = mb_strimwidth($content, 0, $innerWidth, '', 'UTF-8');
        $line = $color . "│" . $reset
            . TuiTextHelper::padString($display, $innerWidth)
            . $color . "│" . $reset;
        echo "\033[{$y};1H" . $line;
    }

    /**
     * Обновляет statusBar, рисует строку статуса и возвращает её содержимое.
     *
     * @param TuiStateDto $state
     * @param LayoutDto   $layout
     * @param int         $width
     * @param StatusBar   $statusBar
     * @return string
     */
    private function buildAndRenderStatusLine(TuiStateDto $state, LayoutDto $layout, int $width, StatusBar $statusBar): string
    {
        $statusLine = $this->buildStatusLineContent($state, $width, $statusBar);
        $this->drawStatusLine($layout, $statusLine, $width);
        return $statusLine;
    }

    /**
     * Возвращает содержимое строки статуса (может содержать ANSI-коды).
     *
     * @param TuiStateDto $state
     * @param int         $width
     * @param StatusBar   $statusBar
     * @return string
     */
    private function buildStatusLineContent(TuiStateDto $state, int $width, StatusBar $statusBar): string
    {
        $mode = $state->getFocus() === TuiStateDto::FOCUS_INPUT ? 'ВВОД' : 'ПРОСМОТР';
        $statusBar->setStatuses([
            new ModeStatus($mode),
            new CursorPositionStatus($state->getCursorRow(), $state->getCursorCol()),
            new HistoryCountStatus(count($state->getHistory())),
        ]);

        return $statusBar->render($width) . " | Tab переключить | Enter отправить | Ctrl+C выход";
    }

    /**
     * Рисует строку состояния.
     *
     * @param LayoutDto $layout
     * @param string    $content
     * @param int       $width
     * @return void
     */
    private function drawStatusLine(LayoutDto $layout, string $content, int $width): void
    {
        $y = $layout->getStatusLine();
        $visibleLength = mb_strwidth((string) preg_replace('/\033\[[0-9;]*m/', '', $content));
        if ($visibleLength > $width) {
            $content = mb_strimwidth($content, 0, $width, '', 'UTF-8');
        } else {
            $content .= str_repeat(' ', $width - $visibleLength);
        }
        echo "\033[{$y};1H" . $content;
    }

    /**
     * Устанавливает курсор в нужную позицию.
     *
     * @param TuiStateDto $state
     * @param LayoutDto   $layout
     * @param int         $width
     * @return void
     */
    private function positionCursor(TuiStateDto $state, LayoutDto $layout, int $width): void
    {
        if ($state->getFocus() === TuiStateDto::FOCUS_INPUT) {
            $row = $layout->getInputContentStart() + $state->getCursorRow();
            $col = 1 + $state->getCursorCol();
            echo "\033[{$row};{$col}H";
            return;
        }

        echo "\033[{$layout->getStatusLine()};{$width}H";
    }
}
