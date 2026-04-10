<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui;

/**
 * DTO состояния интерактивного TUI.
 *
 * Хранит «модель» интерфейса: историю сообщений, состояние ввода, позицию курсора,
 * режим фокуса, прокрутку и служебные поля для частичной перерисовки.
 *
 * DTO намеренно изменяемый (mutable) и использует fluent-сеттеры, т.к. состояние
 * обновляется по событиям клавиатуры в цикле.
 *
 * Пример использования:
 *
 * ```php
 * $state = (new TuiStateDto())
 *     ->setFocus(TuiStateDto::FOCUS_INPUT)
 *     ->setInputLines(['', '', '']);
 * ```
 */
final class TuiStateDto
{
    public const FOCUS_INPUT = 'input';
    public const FOCUS_OUTPUT = 'output';

    /** @var string[] */
    private array $history = [];

    /** @var string[] */
    private array $inputLines = ['', '', ''];

    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private int $outputScroll = 0;

    /** @var string self::FOCUS_INPUT|self::FOCUS_OUTPUT */
    private string $focus = self::FOCUS_INPUT;

    private bool $running = true;
    private bool $fullRedraw = true;

    /** @var string[] */
    private array $prevInputLines = ['', '', ''];

    private string $prevStatusLine = '';

    private int $prevWidth = 0;
    private int $prevHeight = 0;

    /**
     * @return string[]
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * @param string[] $history
     * @return self
     */
    public function setHistory(array $history): self
    {
        $this->history = $history;
        return $this;
    }

    /**
     * Добавляет сообщение в историю.
     *
     * @param string $message
     * @return self
     */
    public function addHistoryMessage(string $message): self
    {
        $this->history[] = $message;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getInputLines(): array
    {
        return $this->inputLines;
    }

    /**
     * @param string[] $inputLines Ожидается массив из 3 строк.
     * @return self
     */
    public function setInputLines(array $inputLines): self
    {
        $this->inputLines = $inputLines;
        return $this;
    }

    public function getCursorRow(): int
    {
        return $this->cursorRow;
    }

    public function setCursorRow(int $cursorRow): self
    {
        $this->cursorRow = $cursorRow;
        return $this;
    }

    public function getCursorCol(): int
    {
        return $this->cursorCol;
    }

    public function setCursorCol(int $cursorCol): self
    {
        $this->cursorCol = $cursorCol;
        return $this;
    }

    public function getOutputScroll(): int
    {
        return $this->outputScroll;
    }

    public function setOutputScroll(int $outputScroll): self
    {
        $this->outputScroll = $outputScroll;
        return $this;
    }

    public function getFocus(): string
    {
        return $this->focus;
    }

    /**
     * @param string $focus self::FOCUS_INPUT|self::FOCUS_OUTPUT
     * @return self
     */
    public function setFocus(string $focus): self
    {
        $this->focus = $focus;
        return $this;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function setRunning(bool $running): self
    {
        $this->running = $running;
        return $this;
    }

    public function isFullRedraw(): bool
    {
        return $this->fullRedraw;
    }

    public function setFullRedraw(bool $fullRedraw): self
    {
        $this->fullRedraw = $fullRedraw;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getPrevInputLines(): array
    {
        return $this->prevInputLines;
    }

    /**
     * @param string[] $prevInputLines
     * @return self
     */
    public function setPrevInputLines(array $prevInputLines): self
    {
        $this->prevInputLines = $prevInputLines;
        return $this;
    }

    public function getPrevStatusLine(): string
    {
        return $this->prevStatusLine;
    }

    public function setPrevStatusLine(string $prevStatusLine): self
    {
        $this->prevStatusLine = $prevStatusLine;
        return $this;
    }

    public function getPrevWidth(): int
    {
        return $this->prevWidth;
    }

    public function setPrevWidth(int $prevWidth): self
    {
        $this->prevWidth = $prevWidth;
        return $this;
    }

    public function getPrevHeight(): int
    {
        return $this->prevHeight;
    }

    public function setPrevHeight(int $prevHeight): self
    {
        $this->prevHeight = $prevHeight;
        return $this;
    }
}
