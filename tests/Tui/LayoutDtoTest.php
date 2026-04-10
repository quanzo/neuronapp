<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\dto\tui\LayoutDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see LayoutDto}.
 *
 * LayoutDto — DTO вычисленной геометрии TUI и производных значений.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\dto\tui\LayoutDto}
 */
class LayoutDtoTest extends TestCase
{
    /**
     * getOutputVisibleLines() возвращает end-start+1.
     */
    public function testOutputVisibleLinesIsEndMinusStartPlusOne(): void
    {
        $layout = new LayoutDto(
            outputContentStart: 2,
            outputContentEnd: 6,
            inputContentStart: 10,
            inputContentEnd: 12,
            statusLine: 13,
        );

        $this->assertSame(5, $layout->getOutputVisibleLines());
    }

    /**
     * Если end < start, видимая высота не уходит в минус (граничное условие).
     */
    public function testOutputVisibleLinesDoesNotGoNegative(): void
    {
        $layout = new LayoutDto(
            outputContentStart: 10,
            outputContentEnd: 9,
            inputContentStart: 10,
            inputContentEnd: 12,
            statusLine: 13,
        );

        $this->assertSame(0, $layout->getOutputVisibleLines());
    }
}
