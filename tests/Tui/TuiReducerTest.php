<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\tui\state\TuiReducer;
use app\modules\neuron\classes\dto\tui\KeyEventDto;
use app\modules\neuron\classes\dto\tui\LayoutDto;
use app\modules\neuron\classes\dto\tui\TuiStateDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryEntryDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see TuiReducer}.
 *
 * TuiReducer — применяет события клавиатуры к состоянию TUI (без IO).
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\tui\state\TuiReducer}
 */
class TuiReducerTest extends TestCase
{
    /**
     * Tab переключает фокус и устанавливает fullRedraw.
     */
    public function testTabTogglesFocusAndRequestsFullRedraw(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())->setFocus(TuiStateDto::FOCUS_INPUT)->setFullRedraw(false);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::tab(), $layout);
        $this->assertSame(TuiStateDto::FOCUS_OUTPUT, $state->getFocus());
        $this->assertTrue($state->isFullRedraw());
    }

    /**
     * Enter в режиме ввода возвращает submittedInput и очищает буфер ввода.
     */
    public function testEnterAddsMessageAndClearsInputWhenFocusInput(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_INPUT)
            ->setInputBuffer("a\nb\nc")
            ->setCursorOffset(5);

        $result = $reducer->applyKeyEventWithResult($state, KeyEventDto::enter(), $layout);
        $state = $result->getState();
        $this->assertSame("a\nb\nc", $result->getSubmittedInput());
        $this->assertSame(0, $state->getHistory()->count());
        $this->assertSame('', $state->getInputBuffer());
        $this->assertSame(0, $state->getCursorOffset());
        $this->assertTrue($state->isFullRedraw());
    }

    /**
     * Enter в режиме просмотра (focus output) не должен изменять историю и ввод.
     */
    public function testEnterDoesNothingWhenFocusOutput(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $history = (new TuiHistoryDto())->append(TuiHistoryEntryDto::output('old'));
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_OUTPUT)
            ->setInputBuffer('x')
            ->setHistory($history);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::enter(), $layout);
        $this->assertSame(1, $state->getHistory()->count());
        $this->assertSame('x', $state->getInputBuffer());
    }

    /**
     * Backspace при cursorOffset = 0 не должен ничего менять (граничное условие).
     */
    public function testBackspaceAtColumnZeroDoesNothing(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_INPUT)
            ->setInputBuffer('ab')
            ->setCursorOffset(0);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::backspace(), $layout);
        $this->assertSame('ab', $state->getInputBuffer());
        $this->assertSame(0, $state->getCursorOffset());
    }

    /**
     * Backspace удаляет символ слева от курсора (включая UTF‑8).
     */
    public function testBackspaceDeletesUtf8Char(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_INPUT)
            ->setInputBuffer('яb')
            ->setCursorOffset(1);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::backspace(), $layout);
        $this->assertSame('b', $state->getInputBuffer());
        $this->assertSame(0, $state->getCursorOffset());
    }

    /**
     * Ctrl+C устанавливает running=false.
     */
    public function testCtrlCStopsRunning(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())->setRunning(true);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::ctrlC(), $layout);
        $this->assertFalse($state->isRunning());
    }

    /**
     * Стрелка вниз в focus output увеличивает outputScroll и требует fullRedraw.
     */
    public function testArrowDownInOutputScrolls(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_OUTPUT)
            ->setOutputScroll(2)
            ->setFullRedraw(false);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::arrowDown(), $layout);
        $this->assertSame(3, $state->getOutputScroll());
        $this->assertTrue($state->isFullRedraw());
    }

    /**
     * PageUp в focus output уменьшает outputScroll на pageSize (visible-1), но не ниже 0.
     */
    public function testPageUpClampsToZero(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(5); // pageSize = 4
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_OUTPUT)
            ->setOutputScroll(3);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::pageUp(), $layout);
        $this->assertSame(0, $state->getOutputScroll());
    }

    /**
     * Вставка текста происходит в позицию курсора и увеличивает cursorOffset.
     */
    public function testTextInsertsAtCursorPosition(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_INPUT)
            ->setInputBuffer('ab')
            ->setCursorOffset(1);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::text('X'), $layout);
        $this->assertSame('aXb', $state->getInputBuffer());
        $this->assertSame(2, $state->getCursorOffset());
    }

    /**
     * Toggle mouse-mode переключает флаг и вызывает full redraw.
     */
    public function testToggleMouseMode(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())->setMouseModeEnabled(false)->setFullRedraw(false);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::toggleMouseMode(), $layout);
        $this->assertTrue($state->isMouseModeEnabled());
        $this->assertTrue($state->isFullRedraw());
    }

    /**
     * Клик мышью в output-area переводит фокус на вывод (если mouse-mode ON).
     */
    public function testMouseClickInOutputSetsFocusOutput(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setMouseModeEnabled(true)
            ->setFocus(TuiStateDto::FOCUS_INPUT)
            ->setFullRedraw(false);

        // y=2 попадает в outputContentStart (см. layoutWithVisibleLines()).
        $state = $reducer->applyKeyEvent($state, KeyEventDto::mouse(0, 5, 2), $layout);
        $this->assertSame(TuiStateDto::FOCUS_OUTPUT, $state->getFocus());
        $this->assertTrue($state->isFullRedraw());
    }

    /**
     * Клик мышью в input-area ставит фокус input и перемещает курсор (если mouse-mode ON).
     */
    public function testMouseClickInInputSetsCursorOffset(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setMouseModeEnabled(true)
            ->setFocus(TuiStateDto::FOCUS_OUTPUT)
            ->setInputBuffer("abc\ndef")
            ->setCursorOffset(0)
            ->setInputViewportTopLine(0)
            ->setFullRedraw(false);

        // inputContentStart в layoutWithVisibleLines() = 22
        // x=3 => clickedCol = x-2 = 1, y=22 => clickedLine = 0
        $state = $reducer->applyKeyEvent($state, KeyEventDto::mouse(0, 3, 22), $layout);
        $this->assertSame(TuiStateDto::FOCUS_INPUT, $state->getFocus());
        $this->assertSame(1, $state->getCursorOffset());
    }

    /**
     * Набор сценариев редактирования буфера ввода (минимум 10 наборов данных, включая неверные).
     */
    #[DataProvider('editingCases')]
    public function testEditingCases(
        string $buffer,
        int $offset,
        KeyEventDto $event,
        string $expectedBuffer,
        int $expectedOffset,
    ): void {
        // Этот тест проверяет корректность обработки границ и неверных offset (отрицательных/слишком больших).
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_INPUT)
            ->setInputBuffer($buffer)
            ->setCursorOffset($offset);

        $state = $reducer->applyKeyEvent($state, $event, $layout);
        $this->assertSame($expectedBuffer, $state->getInputBuffer());
        $this->assertSame($expectedOffset, $state->getCursorOffset());
    }

    /**
     * @return array<string, array{0:string,1:int,2:KeyEventDto,3:string,4:int}>
     */
    public static function editingCases(): array
    {
        return [
            // Вставка в середину.
            'insert_middle' => ['ab', 1, KeyEventDto::text('X'), 'aXb', 2],
            // Вставка UTF-8.
            'insert_utf8' => ['ab', 1, KeyEventDto::text('я'), 'aяb', 2],
            // Backspace в начале — не меняет.
            'backspace_at_start' => ['ab', 0, KeyEventDto::backspace(), 'ab', 0],
            // Backspace в конце — удаляет последний.
            'backspace_at_end' => ['ab', 2, KeyEventDto::backspace(), 'a', 1],
            // Delete в конце — не меняет.
            'delete_at_end' => ['ab', 2, KeyEventDto::delete(), 'ab', 2],
            // Delete в середине.
            'delete_middle' => ['ab', 0, KeyEventDto::delete(), 'b', 0],
            // Home.
            'home_moves_to_line_start' => ["aa\nbb", 4, KeyEventDto::home(), "aa\nbb", 3],
            // End.
            'end_moves_to_line_end' => ["aa\nbb", 3, KeyEventDto::end(), "aa\nbb", 5],
            // Paste многострочный.
            'paste_multiline' => ['x', 1, KeyEventDto::paste("a\nb"), "xa\nb", 4],
            // Неверный offset: отрицательный — clamp.
            'invalid_negative_offset' => ['ab', -10, KeyEventDto::text('X'), 'Xab', 1],
            // Неверный offset: слишком большой — clamp.
            'invalid_too_big_offset' => ['ab', 999, KeyEventDto::text('X'), 'abX', 3],
        ];
    }

    /**
     * Вспомогательный метод: LayoutDto с заданным количеством видимых строк вывода.
     *
     * @param int $visibleLines
     */
    private function layoutWithVisibleLines(int $visibleLines): LayoutDto
    {
        // outputVisibleLines = end-start+1 => end = start + visibleLines - 1
        $start = 2;
        $end = $start + $visibleLines - 1;
        return new LayoutDto(
            outputContentStart: $start,
            outputContentEnd: $end,
            inputContentStart: 22,
            inputContentEnd: 24,
            statusLine: 25,
        );
    }
}
