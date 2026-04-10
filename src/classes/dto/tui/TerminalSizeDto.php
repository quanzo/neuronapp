<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui;

/**
 * DTO размеров терминала.
 *
 * Используется при вычислении геометрии интерфейса и ограничений отрисовки.
 *
 * Пример использования:
 *
 * ```php
 * $size = new TerminalSizeDto(width: 120, height: 40);
 * $width = $size->getWidth();
 * ```
 */
final class TerminalSizeDto
{
    /**
     * @param int $width  Ширина терминала в колонках (>= 0).
     * @param int $height Высота терминала в строках (>= 0).
     */
    public function __construct(
        private readonly int $width,
        private readonly int $height,
    ) {
    }

    /**
     * Возвращает ширину терминала.
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Возвращает высоту терминала.
     */
    public function getHeight(): int
    {
        return $this->height;
    }
}
