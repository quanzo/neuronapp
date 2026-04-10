<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command\hooks;

use app\modules\neuron\classes\dto\tui\PreOutputDecisionDto;
use app\modules\neuron\interfaces\tui\TuiPreOutputHookInterface;

/**
 * Дефолтный pre-hook для TUI: выводит текст как есть.
 *
 * Пример использования:
 *
 * ```php
 * $hook = new DefaultTuiPreOutputHook();
 * $decision = $hook->decide(\"hello\");
 * ```
 */
final class DefaultTuiPreOutputHook implements TuiPreOutputHookInterface
{
    /**
     * {@inheritDoc}
     */
    public function decide(string $originalInput): PreOutputDecisionDto
    {
        return new PreOutputDecisionDto($originalInput, $originalInput);
    }
}
