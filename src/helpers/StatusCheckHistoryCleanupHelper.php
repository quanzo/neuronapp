<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use app\modules\neuron\enums\StatusCheckCleanupDecision;
use NeuronAI\Chat\History\ChatHistoryInterface;

/**
 * Применяет очистку истории после служебного раунда проверки статуса (waitCycle).
 *
 * Для {@see AbstractFullChatHistory} правит полную историю через {@see ChatHistoryEditHelper}.
 * Для обычной {@see \NeuronAI\Chat\History\InMemoryChatHistory} — через truncate и удаление по индексу.
 *
 * Пример:
 *
 * <code>
 * $decision = LlmCycleStatusCheckHelper::resolveCleanupDecision($msgAnswer);
 * if ($decision !== null) {
 *     StatusCheckHistoryCleanupHelper::apply($history, $decision, $countBeforeRound);
 * }
 * </code>
 */
final class StatusCheckHistoryCleanupHelper
{
    /**
     * Выполняет очистку истории по решению и числу сообщений до раунда.
     *
     * @param ChatHistoryInterface $history История чата агента.
     * @param StatusCheckCleanupDecision $decision Удалить пару или только последний user-запрос.
     * @param int $messageCountBeforeRound Число сообщений в истории до вызова sendMessage в раунде.
     */
    public static function apply(
        ChatHistoryInterface $history,
        StatusCheckCleanupDecision $decision,
        int $messageCountBeforeRound
    ): void {
        if ($history instanceof AbstractFullChatHistory) {
            self::applyFullHistory($history, $decision, $messageCountBeforeRound);

            return;
        }

        self::applySimpleHistory($history, $decision, $messageCountBeforeRound);
    }

    /**
     * Очистка для полной истории (файл / in-memory full).
     *
     * @param AbstractFullChatHistory $history История с fullHistory.
     * @param StatusCheckCleanupDecision $decision Режим удаления.
     * @param int $messageCountBeforeRound Счётчик до раунда.
     */
    private static function applyFullHistory(
        AbstractFullChatHistory $history,
        StatusCheckCleanupDecision $decision,
        int $messageCountBeforeRound
    ): void {
        $current = ChatHistoryEditHelper::getFullMessageCount($history);
        if ($current < $messageCountBeforeRound + 1) {
            return;
        }

        if ($decision === StatusCheckCleanupDecision::RemovePair) {
            if ($current >= $messageCountBeforeRound + 2) {
                ChatHistoryEditHelper::deleteLastFullMessages($history, 2);
            } else {
                ChatHistoryEditHelper::deleteLastFullMessages($history, 1);
            }

            return;
        }

        if ($current >= $messageCountBeforeRound + 2) {
            ChatHistoryEditHelper::deleteFullMessageAt($history, $messageCountBeforeRound);
        }
    }

    /**
     * Очистка для истории без полной проекции.
     *
     * @param ChatHistoryInterface $history История чата.
     * @param StatusCheckCleanupDecision $decision Режим удаления.
     * @param int $messageCountBeforeRound Счётчик до раунда.
     */
    private static function applySimpleHistory(
        ChatHistoryInterface $history,
        StatusCheckCleanupDecision $decision,
        int $messageCountBeforeRound
    ): void {
        $current = ChatHistoryTruncateHelper::getMessageCount($history);
        if ($current < $messageCountBeforeRound + 1) {
            return;
        }

        if ($decision === StatusCheckCleanupDecision::RemovePair) {
            ChatHistoryTruncateHelper::truncateToMessageCount($history, $messageCountBeforeRound);

            return;
        }

        if ($current >= $messageCountBeforeRound + 2) {
            ChatHistoryTruncateHelper::deleteMessageAtIndex($history, $messageCountBeforeRound);
        }
    }
}
