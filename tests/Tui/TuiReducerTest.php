<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\command\state\TuiReducer;
use app\modules\neuron\classes\dto\tui\KeyEventDto;
use app\modules\neuron\classes\dto\tui\LayoutDto;
use app\modules\neuron\classes\dto\tui\TuiStateDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see TuiReducer}.
 *
 * TuiReducer — применяет события клавиатуры к состоянию TUI (без IO).
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\command\state\TuiReducer}
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
     * Enter в режиме ввода добавляет сообщение в историю и очищает inputLines.
     */
    public function testEnterAddsMessageAndClearsInputWhenFocusInput(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_INPUT)
            ->setInputLines(['a', 'b', 'c']);

        $result = $reducer->applyKeyEventWithResult($state, KeyEventDto::enter(), $layout);
        $state = $result->getState();
        $this->assertSame("a\nb\nc", $result->getSubmittedInput());
        $this->assertSame([], $state->getHistory());
        $this->assertSame(['', '', ''], $state->getInputLines());
        $this->assertSame(0, $state->getCursorRow());
        $this->assertSame(0, $state->getCursorCol());
        $this->assertTrue($state->isFullRedraw());
    }

    /**
     * Enter в режиме просмотра (focus output) не должен изменять историю и ввод.
     */
    public function testEnterDoesNothingWhenFocusOutput(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_OUTPUT)
            ->setInputLines(['x', '', ''])
            ->setHistory(['old']);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::enter(), $layout);
        $this->assertSame(['old'], $state->getHistory());
        $this->assertSame(['x', '', ''], $state->getInputLines());
    }

    /**
     * Backspace при cursorCol = 0 не должен ничего менять (граничное условие).
     */
    public function testBackspaceAtColumnZeroDoesNothing(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_INPUT)
            ->setInputLines(['ab', '', ''])
            ->setCursorRow(0)
            ->setCursorCol(0);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::backspace(), $layout);
        $this->assertSame(['ab', '', ''], $state->getInputLines());
        $this->assertSame(0, $state->getCursorCol());
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
            ->setInputLines(['яb', '', ''])
            ->setCursorRow(0)
            ->setCursorCol(1);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::backspace(), $layout);
        $this->assertSame(['b', '', ''], $state->getInputLines());
        $this->assertSame(0, $state->getCursorCol());
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
     * Вставка текста происходит в позицию курсора и увеличивает cursorCol.
     */
    public function testTextInsertsAtCursorPosition(): void
    {
        $reducer = new TuiReducer();
        $layout = $this->layoutWithVisibleLines(10);
        $state = (new TuiStateDto())
            ->setFocus(TuiStateDto::FOCUS_INPUT)
            ->setInputLines(['ab', '', ''])
            ->setCursorRow(0)
            ->setCursorCol(1);

        $state = $reducer->applyKeyEvent($state, KeyEventDto::text('X'), $layout);
        $this->assertSame(['aXb', '', ''], $state->getInputLines());
        $this->assertSame(2, $state->getCursorCol());
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
