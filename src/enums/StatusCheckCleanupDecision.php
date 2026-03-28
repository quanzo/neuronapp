<?php

declare(strict_types=1);

namespace app\modules\neuron\enums;

/**
 * Решение очистки истории служебного раунда проверки статуса в {@see \app\modules\neuron\helpers\LlmCycleHelper::waitCycle}.
 *
 * Пример:
 *
 * <code>
 * $history = $agentCfg->getChatHistory();
 * StatusCheckHistoryCleanupHelper::apply($history, StatusCheckCleanupDecision::RemovePair, $countBefore);
 * </code>
 */
enum StatusCheckCleanupDecision
{
    /**
     * Удалить пару запрос (user) + ответ (assistant).
     */
    case RemovePair;

    /**
     * Удалить только пользовательское сообщение с проверкой (неоднозначный ответ ассистента оставить).
     */
    case RemoveUserOnly;
}
