<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\command\hooks\DefaultTuiPostOutputHook;
use app\modules\neuron\classes\dto\tui\PostOutputContextDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see DefaultTuiPostOutputHook}.
 */
class DefaultTuiPostOutputHookTest extends TestCase
{
    /**
     * Post-hook возвращает исходный вывод + дату/время в ожидаемом формате.
     */
    public function testReturnsRenderedOutputWithTimestamp(): void
    {
        $hook = new DefaultTuiPostOutputHook();
        $ctx = new PostOutputContextDto('in', "out\nline2");

        $extra = $hook->afterRender($ctx);
        $this->assertNotNull($extra);
        $this->assertStringContainsString("out\nline2", $extra);
        $this->assertMatchesRegularExpression('/Дата: \\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $extra);
    }
}
