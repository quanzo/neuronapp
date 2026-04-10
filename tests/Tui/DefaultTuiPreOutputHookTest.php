<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\command\hooks\DefaultTuiPreOutputHook;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see DefaultTuiPreOutputHook}.
 */
class DefaultTuiPreOutputHookTest extends TestCase
{
    /**
     * Пустая строка возвращается как есть.
     */
    public function testEmptyStringIsReturnedAsIs(): void
    {
        $hook = new DefaultTuiPreOutputHook();
        $decision = $hook->decide('');
        $this->assertSame('', $decision->getOriginalInput());
        $this->assertSame('', $decision->getOutputText());
    }

    /**
     * Многострочный ввод возвращается как есть.
     */
    public function testMultilineIsReturnedAsIs(): void
    {
        $hook = new DefaultTuiPreOutputHook();
        $input = "a\nb\nc";
        $decision = $hook->decide($input);
        $this->assertSame($input, $decision->getOriginalInput());
        $this->assertSame($input, $decision->getOutputText());
    }
}
