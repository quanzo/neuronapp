<?php

declare(strict_types=1);

namespace Tests\Mind\Support;

use app\modules\neuron\interfaces\HistorySummarizerInterface;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

/**
 * Тестовый double {@see HistorySummarizerInterface}: фиксирует previousSummary без LLM.
 */
final class CapturingHistoryHeadSummarizer implements HistorySummarizerInterface
{
    private ?string $lastPreviousSummary = null;

    /**
     * {@inheritdoc}
     */
    public function summarize(
        array $messages,
        int $contextWindow,
        ?int $maxChars = null,
        ?string $previousSummary = null,
    ): ?Message {
        $this->lastPreviousSummary = $previousSummary;

        if ($messages === [] || $contextWindow <= 0) {
            return null;
        }

        return new Message(MessageRole::DEVELOPER, 'stub-summary-from-capturing-summarizer');
    }

    /**
     * Последнее значение аргумента previousSummary вызова {@see summarize()}.
     */
    public function getLastPreviousSummary(): ?string
    {
        return $this->lastPreviousSummary;
    }
}
