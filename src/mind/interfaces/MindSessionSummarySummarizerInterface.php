<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\interfaces;

use NeuronAI\Chat\Messages\Message;

/**
 * Контракт LLM-суммаризации для {@see \app\modules\neuron\mind\services\MindSessionSummaryService}.
 *
 * Расширяет head-summarizer опциональным предыдущим summary из индекса сессии.
 *
 * Пример:
 *
 * <code>
 * $summary = $summarizer->summarize($tailMessages, $contextWindow, 300, $previousSummary);
 * </code>
 */
interface MindSessionSummarySummarizerInterface
{
    /**
     * Генерирует summary по хвосту истории с опциональным merge предыдущего резюме.
     *
     * @param Message[]   $messages         Сообщения для транскрипта (обычно хвост сессии).
     * @param int         $contextWindow    Размер контекстного окна модели (токены).
     * @param int|null    $maxChars         Лимит длины summary в символах или null.
     * @param string|null $previousSummary  Текущее summary из индекса или null.
     */
    public function summarize(
        array $messages,
        int $contextWindow,
        ?int $maxChars = null,
        ?string $previousSummary = null,
    ): ?Message;
}
