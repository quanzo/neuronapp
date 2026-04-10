<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui;

/**
 * DTO вычисленной геометрии TUI.
 *
 * Содержит абсолютные координаты основных областей интерфейса, рассчитанные
 * из размеров терминала и констант (например, высоты поля ввода).
 *
 * Пример использования:
 *
 * ```php
 * $layout = new LayoutDto(
 *     outputContentStart: 2,
 *     outputContentEnd: 20,
 *     inputContentStart: 22,
 *     inputContentEnd: 24,
 *     statusLine: 25,
 * );
 * ```
 */
final class LayoutDto
{
    /**
     * @param int $outputContentStart Первая строка (Y) содержимого области вывода.
     * @param int $outputContentEnd   Последняя строка (Y) содержимого области вывода.
     * @param int $inputContentStart  Первая строка (Y) содержимого поля ввода.
     * @param int $inputContentEnd    Последняя строка (Y) содержимого поля ввода.
     * @param int $statusLine         Строка (Y) статус-бара.
     */
    public function __construct(
        private readonly int $outputContentStart,
        private readonly int $outputContentEnd,
        private readonly int $inputContentStart,
        private readonly int $inputContentEnd,
        private readonly int $statusLine,
    ) {
    }

    /**
     * Первая строка содержимого области вывода.
     */
    public function getOutputContentStart(): int
    {
        return $this->outputContentStart;
    }

    /**
     * Последняя строка содержимого области вывода.
     */
    public function getOutputContentEnd(): int
    {
        return $this->outputContentEnd;
    }

    /**
     * Количество видимых строк для области вывода.
     */
    public function getOutputVisibleLines(): int
    {
        return max(0, $this->outputContentEnd - $this->outputContentStart + 1);
    }

    /**
     * Первая строка содержимого поля ввода.
     */
    public function getInputContentStart(): int
    {
        return $this->inputContentStart;
    }

    /**
     * Последняя строка содержимого поля ввода.
     */
    public function getInputContentEnd(): int
    {
        return $this->inputContentEnd;
    }

    /**
     * Строка статус-бара.
     */
    public function getStatusLine(): int
    {
        return $this->statusLine;
    }
}
