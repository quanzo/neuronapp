<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\tui\render;

use app\modules\neuron\classes\dto\tui\LayoutDto;
use app\modules\neuron\classes\dto\tui\TerminalSizeDto;
use app\modules\neuron\classes\dto\tui\TuiStateDto;
use app\modules\neuron\classes\dto\tui\view\TuiThemeDto;
use app\modules\neuron\classes\status\CursorPositionStatus;
use app\modules\neuron\classes\status\HistoryCountStatus;
use app\modules\neuron\classes\status\ModeStatus;
use app\modules\neuron\classes\status\MouseModeStatus;
use app\modules\neuron\classes\status\StatusBar;
use app\modules\neuron\helpers\TuiInputBufferHelper;
use app\modules\neuron\helpers\TuiTextHelper;

/**
 * Рендерер TUI: отвечает за отрисовку рамок, содержимого и курсора.
 *
 * Должен получать состояние и геометрию извне и не заниматься чтением ввода.
 *
 * Ключевые принципы:
 * - рендер — это side-effect (echo ANSI), но сам класс не должен выполнять бизнес-логику;
 * - ширина считается в «колонках терминала» (Unicode width), ANSI-коды игнорируются при расчётах;
 * - Windows Terminal чувствителен к рисованию в последнем столбце: в некоторых режимах это может
 *   устанавливать wrap-flag. Поэтому внутреннюю ширину считаем как `width - 3`, оставляя последний столбец пустым.
 * - для стабильности при частых обновлениях активно очищаем строки (ESC[2K) перед перерисовкой строк.
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
    private const CRLF = "\r\n";

    /**
     * Возвращает символы рамки.
     *
     * В Windows Terminal иногда проявляются визуальные артефакты со box-drawing символами
     * при частых перерисовках; для диагностики/обхода можно включить ASCII режим:
     * `NEURON_TUI_ASCII_BORDERS=1`.
     *
     * @return array{h:string,v:string,tl:string,tr:string,bl:string,br:string}
     */
    private function getBorderChars(): array
    {
        $ascii = (string) (getenv('NEURON_TUI_ASCII_BORDERS') ?: '');
        if ($ascii === '1' || strtolower($ascii) === 'true') {
            return ['h' => '-', 'v' => '|', 'tl' => '+', 'tr' => '+', 'bl' => '+', 'br' => '+'];
        }

        return ['h' => '─', 'v' => '│', 'tl' => '┌', 'tr' => '┐', 'bl' => '└', 'br' => '┘'];
    }

    /**
     * Полная перерисовка всего экрана.
     *
     * Делает:
     * - отключает autowrap (DECAWM) на время кадра, очищает экран и ставит курсор в (1,1);
     * - рисует области output и input целиком (включая рамки);
     * - рисует статусную строку (если не отключена через env);
     * - обновляет «prev*» поля в состоянии для последующего partial render;
     * - позиционирует курсор.
     *
     * Важно: метод не должен менять историю/контент; он только визуализирует переданное состояние.
     *
     * @param TuiStateDto      $state
     * @param LayoutDto        $layout
     * @param TerminalSizeDto  $size
     * @param StatusBar        $statusBar
     * @return TuiStateDto
     */
    public function renderFull(TuiStateDto $state, LayoutDto $layout, TerminalSizeDto $size, StatusBar $statusBar): TuiStateDto
    {
        // H_wrap: на время полного кадра отключаем autowrap (DECAWM),
        // чтобы рамки, попадающие ровно в ширину терминала, не ставили wrap-flag.
        echo "\033[?7l\033[2J\033[H";

        $width = $size->getWidth();
        $this->drawOutputAreaFull($state, $layout, $width);
        $this->drawInputAreaFull($state, $layout, $width);

        $statusLine = '';
        $noStatus = (string) (getenv('NEURON_TUI_NO_STATUS') ?: '');
        if (!($noStatus === '1' || strtolower($noStatus) === 'true')) {
            $statusLine = $this->buildAndRenderStatusLine($state, $layout, $width, $statusBar);
        } else {
            $y = $layout->getStatusLine();
            echo "\033[{$y};1H\033[0m\033[2K";
        }

        // prevInputLines хранит последние отрисованные 3 строки viewport поля ввода.
        [$inputLines] = TuiInputBufferHelper::computeViewport(
            $state->getInputBuffer(),
            $state->getCursorOffset(),
            $state->getInputViewportTopLine(),
            3,
        );
        $state->setPrevInputLines($inputLines);
        $state->setPrevStatusLine($statusLine);

        $this->positionCursor($state, $layout, $width);
        // Возвращаем autowrap обратно.
        echo "\033[?7h";
        return $state;
    }

    /**
     * Частичная перерисовка: обновляет только изменившиеся элементы.
     *
     * Концепция:
     * - не трогаем область output (она обновляется при full redraw);
     * - обновляем строку статуса, если она изменилась;
     * - обновляем только те строки viewport поля ввода (3 строки), которые реально изменились.
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

        $noStatus = (string) (getenv('NEURON_TUI_NO_STATUS') ?: '');
        if ($noStatus === '1' || strtolower($noStatus) === 'true') {
            $y = $layout->getStatusLine();
            echo "\033[{$y};1H\033[0m\033[2K";
            $state->setPrevStatusLine('');
        } else {
            $statusLine = $this->buildStatusLineContent($state, $width, $statusBar);
            if ($statusLine !== $state->getPrevStatusLine()) {
                $this->drawStatusLine($layout, $statusLine, $width);
                $state->setPrevStatusLine($statusLine);
            }
        }

        $prevInputLines = $state->getPrevInputLines();
        [$inputLines, $topLine] = TuiInputBufferHelper::computeViewport(
            $state->getInputBuffer(),
            $state->getCursorOffset(),
            $state->getInputViewportTopLine(),
            3,
        );
        $state->setInputViewportTopLine($topLine);
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
     * Полностью рисует область output (рамка + контент истории).
     *
     * Внутренняя ширина берётся как `width - 3`:
     * - 2 символа занимают вертикальные границы;
     * - 1 колонка оставляется пустой справа (защита от артефактов Windows Terminal).
     *
     * @param TuiStateDto $state
     * @param LayoutDto   $layout
     * @param int         $width
     * @return void
     */
    private function drawOutputAreaFull(TuiStateDto $state, LayoutDto $layout, int $width): void
    {
        $color = $state->getFocus() === TuiStateDto::FOCUS_OUTPUT ? self::COLOR_GREEN : self::COLOR_GRAY;
        $reset = self::COLOR_RESET;

        ['h' => $hline, 'v' => $vline, 'tl' => $tl, 'tr' => $tr, 'bl' => $bl, 'br' => $br] = $this->getBorderChars();
        // H_wrapLastColumn: оставляем последнюю колонку пустой, чтобы Windows Terminal
        // не ставил wrap-flag при рисовании рамок в последнем столбце.
        $innerWidth = max(0, $width - 3);

        // Явно позиционируем курсор и очищаем строку, чтобы избежать «дрейфа»/хвостов в Windows Terminal.
        echo "\033[1;1H\033[0m\033[2K" . $color . $tl . str_repeat($hline, $innerWidth) . $tr . $reset;

        $formatter = new TuiHistoryFormatter();
        $displayLines = $formatter->toDisplayLines($state->getHistory(), $innerWidth, new TuiThemeDto());
        $totalLines = count($displayLines);
        $visibleLines = $layout->getOutputVisibleLines();

        $maxScroll = max(0, $totalLines - $visibleLines);
        $outputScroll = min($state->getOutputScroll(), $maxScroll);
        $state->setOutputScroll($outputScroll);

        $startIdx = $outputScroll;
        $endIdx = min($startIdx + $visibleLines, $totalLines);

        for ($i = $startIdx; $i < $endIdx; $i++) {
            $y = $layout->getOutputContentStart() + ($i - $startIdx);
            $line = (string) $displayLines[$i];
            $display = TuiTextHelper::trimAnsiToVisibleWidth($line, $innerWidth);
            echo "\033[{$y};1H\033[0m\033[2K" . $color . $vline . $reset
                . TuiTextHelper::padString($display, $innerWidth)
                . $color . $vline . $reset;
        }

        for ($i = $endIdx - $startIdx; $i < $visibleLines; $i++) {
            $y = $layout->getOutputContentStart() + $i;
            echo "\033[{$y};1H\033[0m\033[2K" . $color . $vline . $reset . str_repeat(' ', $innerWidth) . $color . $vline . $reset;
        }

        $bottomY = $layout->getOutputContentEnd() + 1;
        echo "\033[{$bottomY};1H\033[0m\033[2K" . $color . $bl . str_repeat($hline, $innerWidth) . $br . $reset;
    }

    /**
     * Полностью рисует область input (рамка + 3 строки viewport).
     *
     * @param TuiStateDto $state
     * @param LayoutDto $layout
     * @param int $width
     * @return void
     */
    private function drawInputAreaFull(TuiStateDto $state, LayoutDto $layout, int $width): void
    {
        $color = $state->getFocus() === TuiStateDto::FOCUS_INPUT ? self::COLOR_GREEN : self::COLOR_GRAY;
        $reset = self::COLOR_RESET;

        ['h' => $hline, 'v' => $vline, 'tl' => $tl, 'tr' => $tr, 'bl' => $bl, 'br' => $br] = $this->getBorderChars();
        // H_wrapLastColumn: оставляем последнюю колонку пустой.
        $innerWidth = max(0, $width - 3);

        $topY = $layout->getInputContentStart() - 1;
        echo "\033[{$topY};1H\033[0m\033[2K" . $color . $tl . str_repeat($hline, $innerWidth) . $tr . $reset;

        [$inputLines, $topLine] = TuiInputBufferHelper::computeViewport(
            $state->getInputBuffer(),
            $state->getCursorOffset(),
            $state->getInputViewportTopLine(),
            3,
        );
        $state->setInputViewportTopLine($topLine);
        for ($row = 0; $row < 3; $row++) {
            $content = (string) ($inputLines[$row] ?? '');
            $display = mb_strimwidth($content, 0, $innerWidth, '', 'UTF-8');
            $y = $layout->getInputContentStart() + $row;
            echo "\033[{$y};1H\033[0m\033[2K" . $color . $vline . $reset
                . TuiTextHelper::padString($display, $innerWidth)
                . $color . $vline . $reset;
        }

        $bottomY = $layout->getInputContentEnd() + 1;
        echo "\033[{$bottomY};1H\033[0m\033[2K" . $color . $bl . str_repeat($hline, $innerWidth) . $br . $reset;
    }

    /**
     * Перерисовывает одну строку input-viewport (для partial render).
     *
     * @param TuiStateDto $state
     * @param int $absY Абсолютная строка терминала (1-indexed)
     * @param string $content Содержимое строки viewport (без рамок)
     * @param int $width Ширина терминала (в колонках)
     * @return void
     */
    private function drawInputLine(TuiStateDto $state, int $absY, string $content, int $width): void
    {
        $color = $state->getFocus() === TuiStateDto::FOCUS_INPUT ? self::COLOR_GREEN : self::COLOR_GRAY;
        $reset = self::COLOR_RESET;

        ['v' => $vline] = $this->getBorderChars();
        $innerWidth = max(0, $width - 3);

        $display = mb_strimwidth($content, 0, $innerWidth, '', 'UTF-8');
        echo "\033[{$absY};1H\033[0m\033[2K" . $color . $vline . $reset
            . TuiTextHelper::padString($display, $innerWidth)
            . $color . $vline . $reset;
    }

    /**
     * Рендерит статусную строку и возвращает её контент (для кеширования в state).
     *
     * @param TuiStateDto $state
     * @param LayoutDto $layout
     * @param int $width
     * @param StatusBar $statusBar
     * @return string
     */
    private function buildAndRenderStatusLine(TuiStateDto $state, LayoutDto $layout, int $width, StatusBar $statusBar): string
    {
        $content = $this->buildStatusLineContent($state, $width, $statusBar);
        $this->drawStatusLine($layout, $content, $width);
        return $content;
    }

    /**
     * Собирает строку статуса из статусов (mode/mouse/cursor/history).
     *
     * @param TuiStateDto $state
     * @param int $width
     * @param StatusBar $statusBar
     * @return string
     */
    private function buildStatusLineContent(TuiStateDto $state, int $width, StatusBar $statusBar): string
    {
        $mode = $state->getFocus() === TuiStateDto::FOCUS_INPUT ? 'ВВОД' : 'ПРОСМОТР';
        [$line, $col] = TuiInputBufferHelper::cursorLineCol($state->getInputBuffer(), $state->getCursorOffset());
        $statusBar->setStatuses([
            new ModeStatus($mode),
            new MouseModeStatus($state->isMouseModeEnabled()),
            new CursorPositionStatus($line, $col),
            new HistoryCountStatus($state->getHistory()->count()),
        ]);

        return $statusBar->render($width) . " | Tab переключить | Enter отправить | Ctrl+C выход";
    }

    /**
     * Рисует строку состояния.
     *
     * Защита от артефактов:
     * - не пишем в последний столбец, используем `safeWidth = width - 1`;
     * - предварительно очищаем строку (ESC[2K]).
     *
     * @param LayoutDto $layout
     * @param string $content
     * @param int $width
     * @return void
     */
    private function drawStatusLine(LayoutDto $layout, string $content, int $width): void
    {
        $y = $layout->getStatusLine();
        $safeWidth = max(0, $width - 1);
        $visibleLength = TuiTextHelper::visibleWidth($content);
        if ($visibleLength > $safeWidth) {
            $content = TuiTextHelper::trimAnsiToVisibleWidth($content, $safeWidth);
        } else {
            $content .= str_repeat(' ', $safeWidth - $visibleLength);
        }
        echo "\033[{$y};1H\033[0m\033[2K" . $content;
    }

    /**
     * Устанавливает курсор в нужную позицию.
     *
     * В input-фокусе курсор ставится в координаты viewport (3 строки).
     * В output-фокусе курсор «прячется» на безопасную позицию в последней строке.
     *
     * @param TuiStateDto $state
     * @param LayoutDto $layout
     * @param int $width
     * @return void
     */
    private function positionCursor(TuiStateDto $state, LayoutDto $layout, int $width): void
    {
        if ($state->getFocus() === TuiStateDto::FOCUS_INPUT) {
            [$line, $col0] = TuiInputBufferHelper::cursorLineCol($state->getInputBuffer(), $state->getCursorOffset());
            $relRow = $line - $state->getInputViewportTopLine();
            $relRow = max(0, min(2, $relRow));
            $row = $layout->getInputContentStart() + $relRow;
            $col = 1 + max(0, $col0);
            echo "\033[{$row};{$col}H";
            return;
        }

        $row = $layout->getStatusLine();
        $safeWidth = max(1, $width - 1);
        echo "\033[{$row};{$safeWidth}H";
    }
}
