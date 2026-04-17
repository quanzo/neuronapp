<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\helpers\TuiTextHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты ANSI-safe обрезки: {@see TuiTextHelper::trimAnsiToVisibleWidth()}.
 */
final class TuiTextHelperAnsiTrimTest extends TestCase
{
    /**
     * Обрезка не должна разрывать ANSI-последовательности и должна сохранять видимую ширину.
     */
    public function testTrimAnsiToVisibleWidthDoesNotBreakAnsi(): void
    {
        $green = "\033[92m";
        $reset = "\033[0m";
        $s = $green . 'HELLO-WORLD' . $reset;

        $trimmed = TuiTextHelper::trimAnsiToVisibleWidth($s, 5);
        $this->assertSame(5, TuiTextHelper::visibleWidth($trimmed));
        $this->assertStringContainsString($green, $trimmed);
        $this->assertStringContainsString($reset, $trimmed);
    }
}
