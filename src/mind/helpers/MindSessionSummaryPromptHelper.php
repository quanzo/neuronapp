<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\helpers;

use function trim;

/**
 * Сборка user-промпта для LLM-суммаризации сессии mind (и совместимого head-summarizer).
 *
 * Если задано предыдущее summary — промпт в режиме merge (сохранить факты + дополнить по транскрипту).
 * Иначе — свёртка транскрипта в новое summary.
 *
 * Пример:
 *
 * <code>
 * $prompt = MindSessionSummaryPromptHelper::buildUserPrompt($transcript, $oldSummary, 30);
 * </code>
 */
final class MindSessionSummaryPromptHelper
{
    /**
     * Строит user-промпт для запроса суммаризации.
     *
     * @param string      $transcript       Текстовый транскрипт сообщений.
     * @param string|null $previousSummary  Существующее summary из индекса или null.
     * @param int         $maxWords         Лимит слов в ответе LLM.
     */
    public static function buildUserPrompt(string $transcript, ?string $previousSummary, int $maxWords): string
    {
        $transcript = trim($transcript);
        $previous = self::normalizePreviousSummary($previousSummary);
        $maxWords = max(1, $maxWords);

        if ($previous === null) {
            return self::buildFreshPrompt($transcript, $maxWords);
        }

        return self::buildMergePrompt($transcript, $previous, $maxWords);
    }

    /**
     * Нормализует предыдущее summary: trim; пустая строка → null.
     */
    public static function normalizePreviousSummary(?string $previousSummary): ?string
    {
        if ($previousSummary === null) {
            return null;
        }

        $trimmed = trim($previousSummary);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Промпт без предыдущего summary (первичная свёртка).
     */
    private static function buildFreshPrompt(string $transcript, int $maxWords): string
    {
        return <<<TEXT
            # STRICTLY
            - The maximum word count in the result is __{$maxWords}__ word
            
            # TASK
            Collapse the story into a summary

            #TRANSCRIPT

            ```
            {$transcript}
            ```
        TEXT;
    }

    /**
     * Промпт с merge предыдущего summary и нового транскрипта.
     */
    private static function buildMergePrompt(string $transcript, string $previousSummary, int $maxWords): string
    {
        return <<<TEXT
            # STRICTLY
            - The maximum word count in the result is __{$maxWords}__ word
            
            # TASK
            Update and merge the session summary: keep important facts from PREVIOUS_SUMMARY,
            add and refine based on TRANSCRIPT, do not duplicate wording.

            # PREVIOUS_SUMMARY

            ```
            {$previousSummary}
            ```

            #TRANSCRIPT

            ```
            {$transcript}
            ```
        TEXT;
    }
}
