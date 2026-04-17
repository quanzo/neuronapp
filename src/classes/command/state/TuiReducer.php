<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command\state;

use app\modules\neuron\classes\dto\tui\KeyEventDto;
use app\modules\neuron\classes\dto\tui\LayoutDto;
use app\modules\neuron\classes\dto\tui\ReducerResultDto;
use app\modules\neuron\classes\dto\tui\TuiStateDto;
use app\modules\neuron\helpers\TuiInputBufferHelper;

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
     * Упрощённый API: возвращает только обновлённый `TuiStateDto`.
     * Если нужен «submit» введённого текста (Enter), используйте `applyKeyEventWithResult()`.
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
     * Помимо обновлённого состояния возвращает `submittedInput`, если пользователь подтвердил ввод (Enter)
     * в режиме `FOCUS_INPUT`.
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
            KeyEventDto::TYPE_DELETE => $this->applyDelete($state),
            KeyEventDto::TYPE_HOME => $this->applyHome($state),
            KeyEventDto::TYPE_END => $this->applyEnd($state),
            KeyEventDto::TYPE_CTRL_C => $this->applyCtrlC($state),
            KeyEventDto::TYPE_TEXT => $this->applyText($state, (string) $event->getText()),
            KeyEventDto::TYPE_PASTE => $this->applyPaste($state, (string) $event->getPasteText()),
            KeyEventDto::TYPE_TOGGLE_MOUSE_MODE => $this->applyToggleMouseMode($state),
            KeyEventDto::TYPE_MOUSE => $this->applyMouse($state, $event, $layout),
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

    /**
     * Переключает фокус между input и output.
     *
     * Side-effects для UI:
     * - выставляет `fullRedraw=true`, т.к. меняется цвет рамок/курсор/возможность скролла.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyTab(TuiStateDto $state): TuiStateDto
    {
        $oldFocus = $state->getFocus();
        $state->setFocus($oldFocus === TuiStateDto::FOCUS_INPUT ? TuiStateDto::FOCUS_OUTPUT : TuiStateDto::FOCUS_INPUT);
        $state->setFullRedraw(true);
        return $state;
    }

    /**
     * Обрабатывает Enter.
     *
     * Поведение:
     * - только в `FOCUS_INPUT` считается «submit» ввода;
     * - сохраняет исходный текст в `$submittedInput`;
     * - очищает буфер и сбрасывает курсор/viewport;
     * - ставит `fullRedraw=true`, т.к. история и/или вывод изменятся после обработки команды.
     *
     * @param TuiStateDto    $state
     * @param string|null &$submittedInput Исходный введённый текст, если применим.
     * @return TuiStateDto
     */
    private function applyEnter(TuiStateDto $state, ?string &$submittedInput): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        $submittedInput = $state->getInputBuffer();
        $state->setInputBuffer('')->setCursorOffset(0)->setInputViewportTopLine(0);
        $state->setFullRedraw(true);
        return $state;
    }

    /**
     * Удаляет символ слева от курсора в режиме ввода.
     *
     * Игнорируется, если фокус не на input.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyBackspace(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        [$buf, $off] = TuiInputBufferHelper::backspace($state->getInputBuffer(), $state->getCursorOffset());
        $state->setInputBuffer($buf)->setCursorOffset($off);
        return $state;
    }

    /**
     * Удаляет символ под курсором в режиме ввода (Delete).
     *
     * Игнорируется, если фокус не на input.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyDelete(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        [$buf, $off] = TuiInputBufferHelper::delete($state->getInputBuffer(), $state->getCursorOffset());
        $state->setInputBuffer($buf)->setCursorOffset($off);
        return $state;
    }

    /**
     * Перемещает курсор на начало строки (Home) в режиме ввода.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyHome(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        $state->setCursorOffset(TuiInputBufferHelper::home($state->getInputBuffer(), $state->getCursorOffset()));
        return $state;
    }

    /**
     * Перемещает курсор на конец строки (End) в режиме ввода.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyEnd(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        $state->setCursorOffset(TuiInputBufferHelper::end($state->getInputBuffer(), $state->getCursorOffset()));
        return $state;
    }

    /**
     * Обрабатывает Ctrl+C: сигнал на выход из цикла TUI.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyCtrlC(TuiStateDto $state): TuiStateDto
    {
        $state->setRunning(false);
        return $state;
    }

    /**
     * Включает/выключает режим обработки мыши.
     *
     * Side-effects:
     * - `fullRedraw=true`, т.к. меняются подсказки/индикаторы (status bar) и поведение кликов.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyToggleMouseMode(TuiStateDto $state): TuiStateDto
    {
        $state->setMouseModeEnabled(!$state->isMouseModeEnabled());
        $state->setFullRedraw(true);
        return $state;
    }

    /**
     * Обрабатывает mouse-событие (X10).
     *
     * Ограничения:
     * - события учитываются только если `mouseModeEnabled=true`;
     * - координаты в DTO должны быть валидными (x,y >= 1).
     *
     * Поведение:
     * - клик в области output переводит фокус в output;
     * - клик в области input переводит фокус в input и выставляет курсор по (line,col) внутри viewport.
     *
     * @param TuiStateDto $state
     * @param KeyEventDto $event Должен содержать `mouseX/mouseY`
     * @param LayoutDto $layout Геометрия экранных областей
     * @return TuiStateDto
     */
    private function applyMouse(TuiStateDto $state, KeyEventDto $event, LayoutDto $layout): TuiStateDto
    {
        if (!$state->isMouseModeEnabled()) {
            return $state;
        }

        $x = (int) ($event->getMouseX() ?? 0);
        $y = (int) ($event->getMouseY() ?? 0);
        if ($x <= 0 || $y <= 0) {
            return $state;
        }

        // Клик в области вывода: переводим фокус на output.
        if ($y >= $layout->getOutputContentStart() && $y <= $layout->getOutputContentEnd()) {
            if ($state->getFocus() !== TuiStateDto::FOCUS_OUTPUT) {
                $state->setFocus(TuiStateDto::FOCUS_OUTPUT)->setFullRedraw(true);
            }
            return $state;
        }

        // Клик в области ввода: фокус input + установка курсора.
        if ($y >= $layout->getInputContentStart() && $y <= $layout->getInputContentEnd()) {
            $oldFocus = $state->getFocus();
            $state->setFocus(TuiStateDto::FOCUS_INPUT);
            if ($oldFocus !== TuiStateDto::FOCUS_INPUT) {
                $state->setFullRedraw(true);
            }

            $relRow = $y - $layout->getInputContentStart();
            $clickedLine = $state->getInputViewportTopLine() + $relRow;
            $clickedCol = max(0, $x - 2); // x=1 — рамка, x=2 — col0
            $off = TuiInputBufferHelper::offsetFromLineCol($state->getInputBuffer(), $clickedLine, $clickedCol);
            $state->setCursorOffset($off);
            return $state;
        }

        return $state;
    }

    /**
     * Вставляет один символ/фрагмент текста в позицию курсора.
     *
     * Применяется только в режиме ввода.
     *
     * @param TuiStateDto $state
     * @param string $char
     * @return TuiStateDto
     */
    private function applyText(TuiStateDto $state, string $char): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        if ($char === '') {
            return $state;
        }

        [$buf, $off] = TuiInputBufferHelper::insert($state->getInputBuffer(), $state->getCursorOffset(), $char);
        $state->setInputBuffer($buf)->setCursorOffset($off);
        return $state;
    }

    /**
     * Вставляет текст из bracketed paste в позицию курсора.
     *
     * В отличие от `applyText()`, строка может содержать `\n` и быть длинной.
     *
     * @param TuiStateDto $state
     * @param string $text
     * @return TuiStateDto
     */
    private function applyPaste(TuiStateDto $state, string $text): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        if ($text === '') {
            return $state;
        }

        [$buf, $off] = TuiInputBufferHelper::insert($state->getInputBuffer(), $state->getCursorOffset(), $text);
        $state->setInputBuffer($buf)->setCursorOffset($off);
        return $state;
    }

    /**
     * ArrowUp:
     * - в input: вертикальное перемещение курсора (между строками буфера);
     * - в output: прокрутка истории вверх на 1 строку + full redraw.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyArrowUp(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() === TuiStateDto::FOCUS_INPUT) {
            $off = TuiInputBufferHelper::moveVertically($state->getInputBuffer(), $state->getCursorOffset(), -1);
            $state->setCursorOffset($off);
            return $state;
        }

        $state->setOutputScroll(max(0, $state->getOutputScroll() - 1))->setFullRedraw(true);
        return $state;
    }

    /**
     * ArrowDown:
     * - в input: вертикальное перемещение курсора;
     * - в output: прокрутка вниз на 1 строку + full redraw (clamp выполняется на уровне команды).
     *
     * @param TuiStateDto $state
     * @param LayoutDto $layout
     * @return TuiStateDto
     */
    private function applyArrowDown(TuiStateDto $state, LayoutDto $layout): TuiStateDto
    {
        if ($state->getFocus() === TuiStateDto::FOCUS_INPUT) {
            $off = TuiInputBufferHelper::moveVertically($state->getInputBuffer(), $state->getCursorOffset(), 1);
            $state->setCursorOffset($off);
            return $state;
        }

        // Максимальный скролл вычисляется снаружи (на уровне команды/рендера) и устанавливается отдельно.
        // Здесь ограничиваемся смещением на 1 вниз; clamp будет выполнен позже.
        $state->setOutputScroll($state->getOutputScroll() + 1)->setFullRedraw(true);
        return $state;
    }

    /**
     * ArrowLeft: сдвиг курсора на 1 позицию влево в режиме ввода.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyArrowLeft(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        $state->setCursorOffset(max(0, $state->getCursorOffset() - 1));
        return $state;
    }

    /**
     * ArrowRight: сдвиг курсора на 1 позицию вправо в режиме ввода.
     *
     * @param TuiStateDto $state
     * @return TuiStateDto
     */
    private function applyArrowRight(TuiStateDto $state): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_INPUT) {
            return $state;
        }

        $len = TuiInputBufferHelper::length($state->getInputBuffer());
        if ($state->getCursorOffset() < $len) {
            $state->setCursorOffset($state->getCursorOffset() + 1);
        }
        return $state;
    }

    /**
     * PageUp: постраничная прокрутка вывода вверх.
     *
     * Применяется только в режиме просмотра (`FOCUS_OUTPUT`).
     * Размер страницы берётся как (visibleLines - 1), чтобы оставлять контекст при пролистывании.
     *
     * @param TuiStateDto $state
     * @param LayoutDto $layout
     * @return TuiStateDto
     */
    private function applyPageUp(TuiStateDto $state, LayoutDto $layout): TuiStateDto
    {
        if ($state->getFocus() !== TuiStateDto::FOCUS_OUTPUT) {
            return $state;
        }

        $pageSize = max(1, $layout->getOutputVisibleLines() - 1);
        $state->setOutputScroll(max(0, $state->getOutputScroll() - $pageSize))->setFullRedraw(true);
        return $state;
    }

    /**
     * PageDown: постраничная прокрутка вывода вниз.
     *
     * Применяется только в режиме просмотра (`FOCUS_OUTPUT`).
     * Clamp максимального скролла выполняется на уровне команды/рендера.
     *
     * @param TuiStateDto $state
     * @param LayoutDto $layout
     * @return TuiStateDto
     */
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
