<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui;

use app\modules\neuron\classes\dto\tui\history\TuiHistoryDto;

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
 *     ->setInputBuffer('');
 * ```
 */
final class TuiStateDto
{
    public const FOCUS_INPUT = 'input';
    public const FOCUS_OUTPUT = 'output';

    private TuiHistoryDto $history;

    private string $inputBuffer = '';
    private int $cursorOffset = 0;
    private int $inputViewportTopLine = 0;

    private int $outputScroll = 0;

    /** @var string self::FOCUS_INPUT|self::FOCUS_OUTPUT */
    private string $focus = self::FOCUS_INPUT;

    private bool $running = true;
    private bool $fullRedraw = true;
    private bool $mouseModeEnabled = false;

    /** @var string[] */
    private array $prevInputLines = ['', '', ''];

    private string $prevStatusLine = '';

    private int $prevWidth = 0;
    private int $prevHeight = 0;

    /**
     * Создаёт DTO состояния с пустой историей.
     *
     * История инициализируется внутри конструктора, чтобы состояние было валидным сразу после создания.
     *
     * @return void
     */
    public function __construct()
    {
        $this->history = new TuiHistoryDto();
    }

    /**
     * Возвращает историю сообщений/entries, отображаемую в области output.
     *
     * @return TuiHistoryDto
     */
    public function getHistory(): TuiHistoryDto
    {
        return $this->history;
    }

    /**
     * Устанавливает историю сообщений/entries.
     *
     * Важно: история — объект, который переиспользуется между рендерами; избегайте
     * неожиданных мутаций DTO внутри форматтеров/рендера.
     *
     * @param TuiHistoryDto $history
     * @return self
     */
    public function setHistory(TuiHistoryDto $history): self
    {
        $this->history = $history;
        return $this;
    }

    /**
     * Текущий буфер ввода (может быть многострочным, содержит `\n`).
     */
    public function getInputBuffer(): string
    {
        return $this->inputBuffer;
    }

    /**
     * Устанавливает буфер ввода.
     *
     * @param string $inputBuffer
     * @return self
     */
    public function setInputBuffer(string $inputBuffer): self
    {
        $this->inputBuffer = $inputBuffer;
        return $this;
    }

    /**
     * Позиция курсора в буфере ввода (смещение в «символах», не в байтах).
     */
    public function getCursorOffset(): int
    {
        return $this->cursorOffset;
    }

    /**
     * Устанавливает позицию курсора в буфере ввода.
     *
     * Значение нормализуется к неотрицательному.
     *
     * @param int $cursorOffset
     * @return self
     */
    public function setCursorOffset(int $cursorOffset): self
    {
        $this->cursorOffset = max(0, $cursorOffset);
        return $this;
    }

    /**
     * Верхняя строка viewport ввода (для отображения части многострочного буфера).
     */
    public function getInputViewportTopLine(): int
    {
        return $this->inputViewportTopLine;
    }

    /**
     * Устанавливает верхнюю строку viewport ввода.
     *
     * @param int $inputViewportTopLine
     * @return self
     */
    public function setInputViewportTopLine(int $inputViewportTopLine): self
    {
        $this->inputViewportTopLine = max(0, $inputViewportTopLine);
        return $this;
    }

    /**
     * Текущее смещение прокрутки области output (в строках истории).
     */
    public function getOutputScroll(): int
    {
        return $this->outputScroll;
    }

    /**
     * Устанавливает смещение прокрутки output.
     *
     * Clamp до допустимого диапазона выполняется на уровне команды/рендера.
     *
     * @param int $outputScroll
     * @return self
     */
    public function setOutputScroll(int $outputScroll): self
    {
        $this->outputScroll = $outputScroll;
        return $this;
    }

    /**
     * Текущий фокус интерфейса: input или output.
     *
     * @return string self::FOCUS_INPUT|self::FOCUS_OUTPUT
     */
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

    /**
     * Управляет флагом работы цикла TUI.
     *
     * @param bool $running
     * @return self
     */
    public function setRunning(bool $running): self
    {
        $this->running = $running;
        return $this;
    }

    public function isFullRedraw(): bool
    {
        return $this->fullRedraw;
    }

    /**
     * Управляет флагом необходимости полной перерисовки.
     *
     * Используется, когда меняется output/фокус/скролл/режимы, влияющие на отрисовку.
     *
     * @param bool $fullRedraw
     * @return self
     */
    public function setFullRedraw(bool $fullRedraw): self
    {
        $this->fullRedraw = $fullRedraw;
        return $this;
    }

    public function isMouseModeEnabled(): bool
    {
        return $this->mouseModeEnabled;
    }

    /**
     * Включает/выключает обработку mouse events (при активном reporting в терминале).
     *
     * @param bool $mouseModeEnabled
     * @return self
     */
    public function setMouseModeEnabled(bool $mouseModeEnabled): self
    {
        $this->mouseModeEnabled = $mouseModeEnabled;
        return $this;
    }

    /**
     * Последние отрисованные строки viewport input (для partial render).
     *
     * @return string[]
     */
    public function getPrevInputLines(): array
    {
        return $this->prevInputLines;
    }

    /**
     * Устанавливает кеш предыдущих строк ввода (для partial render).
     *
     * @param string[] $prevInputLines
     * @return self
     */
    public function setPrevInputLines(array $prevInputLines): self
    {
        $this->prevInputLines = $prevInputLines;
        return $this;
    }

    /**
     * Возвращает последнюю отрисованную строку статуса (для сравнения в partial render).
     */
    public function getPrevStatusLine(): string
    {
        return $this->prevStatusLine;
    }

    /**
     * Устанавливает последнюю отрисованную строку статуса.
     *
     * @param string $prevStatusLine
     * @return self
     */
    public function setPrevStatusLine(string $prevStatusLine): self
    {
        $this->prevStatusLine = $prevStatusLine;
        return $this;
    }

    /**
     * Предыдущая ширина терминала (для детекта ресайза).
     */
    public function getPrevWidth(): int
    {
        return $this->prevWidth;
    }

    /**
     * Устанавливает предыдущую ширину терминала.
     *
     * @param int $prevWidth
     * @return self
     */
    public function setPrevWidth(int $prevWidth): self
    {
        $this->prevWidth = $prevWidth;
        return $this;
    }

    /**
     * Предыдущая высота терминала (для детекта ресайза).
     */
    public function getPrevHeight(): int
    {
        return $this->prevHeight;
    }

    /**
     * Устанавливает предыдущую высоту терминала.
     *
     * @param int $prevHeight
     * @return self
     */
    public function setPrevHeight(int $prevHeight): self
    {
        $this->prevHeight = $prevHeight;
        return $this;
    }
}
