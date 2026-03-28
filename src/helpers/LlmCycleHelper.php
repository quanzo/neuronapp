<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;

/**
 * Циклы ожидания завершения задачи LLM и повтор итогового сообщения.
 *
 * Пример:
 *
 * <code>
 * LlmCycleHelper::waitCycle($sessionCfg);
 * LlmCycleHelper::repeateResultMsg($sessionCfg);
 * </code>
 */
class LlmCycleHelper
{
    const MSG_CHECK_WORK = "Have you completed the all current task? Strict answer only `YES` or `NO`! If you're waiting, strict answer `WAITING` only! If your answer is `NO`, then continue execute!";
    const MSG_CONTINUE =  "Сontinue with the task";
    const MSG_RESULT = "Repeat the final message";
    const MSG_CHECK_WORK2 = "Is the task complete? Answer only YES or NO! If NO, continue!";

    /**
     * Определяет, можно ли завершить цикл ожидания по ответу LLM (YES/WAITING или структурированный ответ).
     *
     * @param mixed $msgAnswer Ответ агента (сообщение или DTO).
     * @return bool true — цикл waitCycle можно завершить.
     */
    public static function checkEndCycle(mixed $msgAnswer): bool
    {
        if ($msgAnswer) {
            if ($msgAnswer instanceof NeuronMessage) {
                $cnt = $msgAnswer->getContent();
                if ($cnt && (mb_strpos($cnt, 'YES') !== false || mb_strpos($cnt, 'WAITING') !== false)) {
                    // LLM четко ответила, что закончила работу
                    return true;
                }
                // YES не нашли
                return false;
            }
            // LLM ответила структурированными данными и значит завершила работу
            return true;
        }
        return false;
    }

    /**
     * Цикл опроса LLM до подтверждения завершения задачи; служебные реплики проверки убираются из истории чата.
     *
     * @param ConfigurationAgent $agentCfg Конфигурация агента с историей сессии.
     * @return array{ok: bool, cycles: int} Результат и число итераций опроса.
     */
    public static function waitCycle(ConfigurationAgent $agentCfg): array
    {
        $msgTest = new NeuronMessage(MessageRole::USER, LlmCycleHelper::MSG_CHECK_WORK);
        $cycleCount = 0;
        do {
            $history = $agentCfg->getChatHistory();
            $countBefore = $history instanceof AbstractFullChatHistory
                ? ChatHistoryEditHelper::getFullMessageCount($history)
                : ChatHistoryTruncateHelper::getMessageCount($history);

            $msgAnswer = $agentCfg->sendMessage($msgTest);
            $cleanup = LlmCycleStatusCheckHelper::resolveCleanupDecision($msgAnswer);
            //$cleanup = null;
            if ($cleanup !== null) {
                StatusCheckHistoryCleanupHelper::apply($history, $cleanup, $countBefore);
            }

            $cycleIsEnd = static::checkEndCycle($msgAnswer);
            $cycleCount++;
        } while (!$cycleIsEnd);

        return [
            'ok'     => true,
            'cycles' => $cycleCount,
        ];
    }

    /**
     * Запрашивает у LLM повтор итогового сообщения (ожидаемое последнее сообщение в истории по задачам).
     *
     * @param ConfigurationAgent $agentCfg Конфигурация агента.
     * @return NeuronMessage|object|null Ответ агента.
     */
    public static function repeateResultMsg(ConfigurationAgent $agentCfg): mixed
    {
        $msgTest = new NeuronMessage(MessageRole::USER, LlmCycleHelper::MSG_RESULT);
        $msgAnswer = $agentCfg->sendMessage($msgTest);

        return $msgAnswer;
    }
}
