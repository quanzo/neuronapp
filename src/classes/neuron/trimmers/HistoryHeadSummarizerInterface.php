<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\trimmers;

use NeuronAI\Chat\Messages\Message;

/**
 * Интерфейс генератора summary для «головы» истории.
 *
 * Используется триммерами, которые при переполнении контекстного окна сворачивают
 * старые сообщения в одно (или несколько) компактных summary-сообщений.
 *
 * Контракт:
 * - реализация НЕ должна изменять переданные сообщения;
 * - реализация должна возвращать одно сообщение summary (обычно роль DEVELOPER) либо null,
 *   если summary получить невозможно или оно не требуется.
 *
 * Пример:
 *
 * <code>
 * $summarizer = new ConfigurationAgentHistoryHeadSummarizer($agentCfg);
 * $summary = $summarizer->summarize($head, $contextWindow);
 * </code>
 */
interface HistoryHeadSummarizerInterface
{
    /**
     * Генерирует одно summary-сообщение для «головы» истории.
     *
     * @param Message[] $headMessages Сообщения, которые нужно свернуть в summary.
     * @param int $contextWindow Размер контекстного окна (в токенах) для модели.
     */
    public function summarize(array $headMessages, int $contextWindow): ?Message;
}
