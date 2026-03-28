<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\enums\StatusCheckCleanupDecision;
use NeuronAI\Chat\Messages\Message as NeuronMessage;

/**
 * Классификация ответа LLM на служебный запрос проверки статуса задачи и выбор стратегии очистки истории.
 *
 * Пример:
 *
 * <code>
 * $decision = LlmCycleStatusCheckHelper::resolveCleanupDecision($msgAnswer);
 * if ($decision !== null) {
 *     StatusCheckHistoryCleanupHelper::apply($history, $decision, $countBefore);
 * }
 * </code>
 */
final class LlmCycleStatusCheckHelper
{
    /**
     * Возвращает решение очистки истории после раунда или null, если чистить не нужно.
     *
     * @param mixed $msgAnswer Ответ агента (сообщение, DTO или null).
     */
    public static function resolveCleanupDecision(mixed $msgAnswer): ?StatusCheckCleanupDecision
    {
        if ($msgAnswer === null || $msgAnswer === false) {
            return null;
        }

        if (!$msgAnswer instanceof NeuronMessage) {
            return StatusCheckCleanupDecision::RemovePair;
        }

        $text = self::normalizeMessageContentAsString($msgAnswer);
        if ($text === '') {
            return StatusCheckCleanupDecision::RemovePair;
        }

        if (self::hasExplicitStatusKeyword($text)) {
            return StatusCheckCleanupDecision::RemovePair;
        }

        return StatusCheckCleanupDecision::RemoveUserOnly;
    }

    /**
     * Проверяет наличие явного ответа YES, NO или WAITING как отдельных слов (латиница без ложных срабатываний вроде KNOW).
     *
     * @param string $text Текст ответа.
     */
    public static function hasExplicitStatusKeyword(string $text): bool
    {
        return 1 === preg_match(
            '/(?<![A-Za-z])(YES|NO|WAITING)(?![A-Za-z])/i',
            $text
        );
    }

    /**
     * Приводит содержимое сообщения к строке для анализа.
     */
    private static function normalizeMessageContentAsString(NeuronMessage $message): string
    {
        $content = $message->getContent();
        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            return '';
        }

        return trim((string) $content);
    }
}
