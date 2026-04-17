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
     * Пустая строка добавляется как output-entry (совместимость).
     */
    public function testEmptyStringIsReturnedAsIs(): void
    {
        $hook = new DefaultTuiPreOutputHook();
        $decision = $hook->decide('');
        $this->assertSame('', $decision->getOriginalInput());
        $this->assertCount(1, $decision->getAppendEntries());
        $this->assertSame('', $decision->getAppendEntries()[0]->getPlainText());
    }

    /**
     * Многострочный ввод добавляется как output-entry без изменений.
     */
    public function testMultilineIsReturnedAsIs(): void
    {
        $hook = new DefaultTuiPreOutputHook();
        $input = "a\nb\nc";
        $decision = $hook->decide($input);
        $this->assertSame($input, $decision->getOriginalInput());
        $this->assertCount(1, $decision->getAppendEntries());
        $this->assertSame($input, $decision->getAppendEntries()[0]->getPlainText());
    }
}
