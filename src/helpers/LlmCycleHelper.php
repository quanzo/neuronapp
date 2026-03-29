<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\enums\LlmCyclePollStatus;
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
    public const MSG_CHECK_WORK = "Have you completed the all current task? Strict answer only `YES` or `NO`! If you're waiting, strict answer `WAITING` only! If your answer is `NO`, then continue execute!";
    public const MSG_CONTINUE = 'Сontinue with the task';
    public const MSG_RESULT = 'Repeat the final message';
    public const MSG_CHECK_WORK2 = 'Is the task complete? Answer only YES or NO! If NO, continue!';

    /**
     * Цикл опроса LLM до подтверждения завершения задачи; служебные реплики проверки при необходимости убираются из истории.
     *
     * Явные ответы NO/WAITING увеличивают счётчик «ясных» незавершений (не более $maxCycleCount).\n
     * Невнятные ответы не увеличивают этот счётчик, но увеличивают число раундов sendMessage; при превышении $maxTotalRounds цикл прерывается (защита от зацикливания).
     *
     * @param ConfigurationAgent $agentCfg        Конфигурация агента с историей сессии.
     * @param int                $maxCycleCount   Максимум явных ответов «ещё в работе» (NO/WAITING).
     * @param int|null           $maxTotalRounds  Верхняя граница числа вызовов sendMessage в этом waitCycle; null — max(30, maxCycleCount * 6).
     *
     * @return array{ok: bool, cycles: int, clearProgressCount: int, totalRounds: int}
     */
    public static function waitCycle(ConfigurationAgent $agentCfg, int $maxCycleCount = 10, ?int $maxTotalRounds = null): array
    {
        $maxTotalRounds ??= max(30, $maxCycleCount * 6);

        $msgTest = new NeuronMessage(MessageRole::USER, self::MSG_CHECK_WORK);

        $clearProgressCount = 0;
        $totalRounds        = 0;
        $completed          = false;

        while ($totalRounds < $maxTotalRounds) {
            ++$totalRounds;

            $history     = $agentCfg->getChatHistory();
            $countBefore = ChatHistoryRollbackHelper::getSnapshotCount($history);

            $msgAnswer = $agentCfg->sendMessage($msgTest);
            $cleanup   = LlmCycleStatusCheckHelper::resolveCleanupDecision($msgAnswer);
            //$cleanup     = null;
            if ($cleanup !== null) {
                StatusCheckHistoryCleanupHelper::apply($history, $cleanup, $countBefore);
            }

            $status = LlmCyclePollStatus::fromAgentAnswer($msgAnswer);

            if ($status === LlmCyclePollStatus::Completed) {
                $completed = true;
                break;
            }

            if ($status === LlmCyclePollStatus::InProgress) {
                ++$clearProgressCount;
                if ($clearProgressCount >= $maxCycleCount) {
                    break;
                }

                continue;
            }

            // Unclear: счётчик явных «в работе» не увеличиваем; totalRounds уже учтён.
        }

        return [
            'ok'                 => $completed,
            'cycles'             => $totalRounds,
            'clearProgressCount' => $clearProgressCount,
            'totalRounds'        => $totalRounds,
        ];
    }

    /**
     * Запрашивает у LLM повтор итогового сообщения (ожидаемое последнее сообщение в истории по задачам).
     *
     * @param ConfigurationAgent $agentCfg Конфигурация агента.
     *
     * @return NeuronMessage|object|null Ответ агента.
     */
    public static function repeateResultMsg(ConfigurationAgent $agentCfg): mixed
    {
        $msgTest   = new NeuronMessage(MessageRole::USER, self::MSG_RESULT);
        $msgAnswer = $agentCfg->sendMessage($msgTest);

        return $msgAnswer;
    }
}
