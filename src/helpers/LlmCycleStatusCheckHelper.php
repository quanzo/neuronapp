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
 * $raw = LlmCycleStatusCheckHelper::decisionForNeuronRawAndNormalizedText(['text'], '');
 * // null — непустой массив блоков, пару не трогаем
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

        /** @var mixed $rawContent Реализация может отдавать массив блоков; у стандартного {@see NeuronMessage} — строка или null. */
        $rawContent = $msgAnswer->getContent();
        if (\is_array($rawContent)) {
            return self::decisionForNeuronRawAndNormalizedText($rawContent, '');
        }

        $text = self::normalizeMessageContentAsString($msgAnswer);

        return self::decisionForNeuronRawAndNormalizedText($rawContent, $text);
    }

    /**
     * Решение по «сырому» getContent и нормализованному тексту (для тестов и нестандартных сообщений).
     *
     * Непустой массив в $rawContent — контент-блоки; пару сообщений не удаляем (null).
     * Пустой массив — как пустой ответ, удаляем пару.
     *
     * @param mixed $rawContent Значение getContent() у сообщения.
     * @param string $normalizedText Текст после trim, если $rawContent не массив.
     */
    public static function decisionForNeuronRawAndNormalizedText(
        mixed $rawContent,
        string $normalizedText
    ): ?StatusCheckCleanupDecision {
        if (\is_array($rawContent)) {
            return $rawContent === []
                ? StatusCheckCleanupDecision::RemovePair
                : null;
        }

        if ($normalizedText === '') {
            return StatusCheckCleanupDecision::RemovePair;
        }

        if (self::hasExplicitStatusKeyword($normalizedText)) {
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
     * Приводит содержимое сообщения к строке для анализа (массив блоков обрабатывается в {@see resolveCleanupDecision()}).
     */
    private static function normalizeMessageContentAsString(NeuronMessage $message): string
    {
        $content = $message->getContent();
        if (\is_string($content)) {
            return trim($content);
        }

        return trim((string) $content);
    }
}
