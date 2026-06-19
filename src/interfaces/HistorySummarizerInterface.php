<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces;

use NeuronAI\Chat\Messages\Message;

/**
 * Контракт LLM-суммаризации фрагмента истории сообщений.
 *
 * Используется:
 * - {@see \app\modules\neuron\classes\neuron\trimmers\CclCodeHistoryTrimmer} — свёртка «головы» истории
 *   при переполнении контекстного окна (CCL compact);
 * - {@see \app\modules\neuron\mind\services\MindSessionSummaryService} — краткое описание сессии
 *   в индексе `.mind/sessions.md`.
 *
 * Контракт:
 * - реализация НЕ должна изменять переданные сообщения;
 * - возвращает одно summary-сообщение (обычно роль DEVELOPER) либо null,
 *   если summary получить невозможно или оно не требуется.
 *
 * Пример:
 *
 * <code>
 * // CCL compact — только сообщения и окно
 * $summary = $summarizer->summarize($headMessages, $contextWindow);
 *
 * // Mind session summary — с лимитом и merge предыдущего резюме
 * $summary = $summarizer->summarize($tailMessages, $contextWindow, 300, $previousSummary);
 * </code>
 */
interface HistorySummarizerInterface
{
    /**
     * Генерирует одно summary-сообщение по переданным сообщениям.
     *
     * @param Message[]   $messages         Сообщения для транскрипта (голова или хвост истории).
     * @param int         $contextWindow    Размер контекстного окна модели (токены).
     * @param int|null    $maxChars         Лимит длины summary в символах (mind) или null (дефолт реализации).
     * @param string|null $previousSummary  Текущее summary для merge-режима (mind) или null (первичная свёртка).
     */
    public function summarize(
        array $messages,
        int $contextWindow,
        ?int $maxChars = null,
        ?string $previousSummary = null,
    ): ?Message;
}
