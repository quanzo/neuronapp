<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command\state;

use app\modules\neuron\classes\dto\tui\KeyEventDto;
use app\modules\neuron\classes\dto\tui\LayoutDto;
use app\modules\neuron\classes\dto\tui\ReducerResultDto;
use app\modules\neuron\classes\dto\tui\TuiStateDto;

/**
 * Reducer (pure-ish) для применения событий клавиатуры к состоянию TUI.
 *
 * Инкапсулирует правила изменения состояния, чтобы:
 * - упростить тестирование (без IO);
 * - исключить дублирование логики в команде;
 * - сделать поведение предсказуемым.
 *
 * Пример использования:
 *
 * ```php
 * $state = (new TuiStateDto());
 * $state = (new TuiReducer())->applyKeyEvent($state, KeyEventDto::tab(), $layout);
 * ```
 */
final class TuiReducer
{
    /**
     * Применяет одно событие к состоянию.
     *
     * @param TuiStateDto $state
     * @param KeyEventDto $event
     * @param LayoutDto   $layout
     * @return TuiStateDto
     */
    public function applyKeyEvent(TuiStateDto $state, KeyEventDto $event, LayoutDto $layout): TuiStateDto
    {
        return $this->applyKeyEventWithResult($state, $event, $layout)->getState();
    }

    /**
     * Применяет одно событие к состоянию и возвращает расширенный результат.
     *
     * @param TuiStateDto $state
     * @param KeyEventDto $event
     * @param LayoutDto   $layout
     * @return ReducerResultDto
     */
    public function applyKeyEventWithResult(TuiStateDto $state, KeyEventDto $event, LayoutDto $layout): ReducerResultDto
    {
        $submittedInput = null;

        $state = match ($event->getType()) {
            KeyEventDto::TYPE_TAB => $this->applyTab($state),
            KeyEventDto::TYPE_ENTER => $this->applyEnter($state, $submittedInput),
            KeyEventDto::TYPE_BACKSPACE => $this->applyBackspace($state),
            KeyEventDto::TYPE_CTRL_C => $this->applyCtrlC($state),
            KeyEventDto::TYPE_TEXT => $this->applyText($state, (string) $event->getText()),
            KeyEventDto::TYPE_ARROW_UP => $this->applyArrowUp($state),
            KeyEventDto::TYPE_ARROW_DOWN => $this->applyArrowDown($state, $layout),
            KeyEventDto::TYPE_ARROW_LEFT => $this->applyArrowLeft($state),
            KeyEventDto::TYPE_ARROW_RIGHT => $this->applyArrowRight($state),
            KeyEventDto::TYPE_PAGE_UP => $this->applyPageUp($state, $layout),
            KeyEventDto::TYPE_PAGE_DOWN => $this->applyPageDown($state, $layout),
            default => $state,
        };

        return new ReducerResultDto($state, $submittedInput);
    }

    private function applyTab(TuiStateDto $state): TuiStateDto
    {
        $oldFocus = $state->getFocus();
        $state->setFocus($oldFocus === TuiStateDto::FOCUS_INPUT ? TuiStateDto::FOCUS_OUTPUT : TuiStateDto::FOCUS_INPUT);
        $state->setFullRedraw(true);
        return $state;
    }

    /**
     * @param TuiStateDto    $state
     * @param string|null &$submittedInput Исходный введённый текст, если применим.
     * @return TuiStateDto
     */
    private function applyEnter(TuiStateDto $state, ?string &$submittedInput): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        $submittedInput = implode("\n", $state->getInputLines());
        $state->setInputLines(['', '', ''])->setCursorRow(0)->setCursorCol(0);
        $state->setFullRedraw(true);
        return $state;
    }

    private function applyBackspace(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        if ($state->getCursorCol() <= 0) {
            return $state;
        }

        $row = $state->getCursorRow();
        $col = $state->getCursorCol();
        $lines = $state->getInputLines();
        $line = (string) ($lines[$row] ?? '');

        $lines[$row] = mb_substr($line, 0, $col - 1) . mb_substr($line, $col);

        $state->setInputLines($lines)->setCursorCol($col - 1);
        return $state;
    }

    private function applyCtrlC(TuiStateDto $state): TuiStateDto
    {
        $state->setRunning(false);
        return $state;
    }

    private function applyText(TuiStateDto $state, string $char): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        if ($char === '') {
            return $state;
        }

        $row = $state->getCursorRow();
        $col = $state->getCursorCol();
        $lines = $state->getInputLines();
        $line = (string) ($lines[$row] ?? '');
        $lines[$row] = mb_substr($line, 0, $col) . $char . mb_substr($line, $col);

        $state->setInputLines($lines)->setCursorCol($col + 1);
        return $state;
    }

    private function applyArrowUp(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() === TuiStateDto::FOCUS_INPUT) {
            $state->setCursorRow(max(0, $state->getCursorRow() - 1));
            return $state;
        }

        $state->setOutputScroll(max(0, $state->getOutputScroll() - 1))->setFullRedraw(true);
        return $state;
    }

    private function applyArrowDown(TuiStateDto $state, LayoutDto $layout): TuiStateDto
    {
        if ($state->getFocus() === TuiStateDto::FOCUS_INPUT) {
            $state->setCursorRow(min(2, $state->getCursorRow() + 1));
            return $state;
        }

        // Максимальный скролл вычисляется снаружи (на уровне команды/рендера) и устанавливается отдельно.
        // Здесь ограничиваемся смещением на 1 вниз; clamp будет выполнен позже.
        $state->setOutputScroll($state->getOutputScroll() + 1)->setFullRedraw(true);
        return $state;
    }

    private function applyArrowLeft(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        $state->setCursorCol(max(0, $state->getCursorCol() - 1));
        return $state;
    }

    private function applyArrowRight(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        $row = $state->getCursorRow();
        $lines = $state->getInputLines();
        $len = mb_strlen((string) ($lines[$row] ?? ''));
        if ($state->getCursorCol() < $len) {
            $state->setCursorCol($state->getCursorCol() + 1);
        }
        return $state;
    }

    private function applyPageUp(TuiStateDto $state, LayoutDto $layout): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_OUTPUT) {
            return $state;
        }

        $pageSize = max(1, $layout->getOutputVisibleLines() - 1);
        $state->setOutputScroll(max(0, $state->getOutputScroll() - $pageSize))->setFullRedraw(true);
        return $state;
    }

    private function applyPageDown(TuiStateDto $state, LayoutDto $layout): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_OUTPUT) {
            return $state;
        }

        $pageSize = max(1, $layout->getOutputVisibleLines() - 1);
        $state->setOutputScroll($state->getOutputScroll() + $pageSize)->setFullRedraw(true);
        return $state;
    }
}
