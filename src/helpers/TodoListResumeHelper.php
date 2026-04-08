<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\run\TodoListResumePlanDto;

/**
 * Хелпер вычисления и применения resume-плана для TodoList.
 *
 * Нужен как общая точка логики для CLI-команд, orchestrator и других мест,
 * где используется checkpoint `RunStateDto`.
 *
 * Пример:
 * <code>
 * $plan = TodoListResumeHelper::buildPlan($agentCfg, 'step', '20250301-143022-123456-0');
 * if ($plan->isResumeAvailable()) {
 *     TodoListResumeHelper::applyHistoryRollback($agentCfg, $plan);
 * }
 * </code>
 */
final class TodoListResumeHelper
{
    /**
     * Строит DTO-план возобновления TodoList.
     *
     * @param ConfigurationAgent $agentCfg            Конфигурация агента с доступом к checkpoint.
     * @param string             $todolistName        Имя списка, для которого строится plan.
     * @param string|null        $expectedSessionKey  Ожидаемый session key приложения; null отключает проверку.
     *
     * @return TodoListResumePlanDto Готовый план resume.
     */
    public static function buildPlan(
        ConfigurationAgent $agentCfg,
        string $todolistName,
        ?string $expectedSessionKey = null
    ): TodoListResumePlanDto {
        $plan = new TodoListResumePlanDto();
        $runStateDto = $agentCfg->getExistRunStateDto();

        if ($runStateDto === null) {
            return $plan->setReason('no_checkpoint');
        }

        $plan->setRunStateDto($runStateDto);

        if ($runStateDto->isFinished()) {
            return $plan->setReason('finished');
        }

        if ($runStateDto->getTodolistName() !== $todolistName) {
            return $plan->setReason('todolist_mismatch');
        }

        $checkpointSessionKey = $runStateDto->getSessionKey();
        if (
            $expectedSessionKey !== null
            && $checkpointSessionKey !== ''
            && $checkpointSessionKey !== $expectedSessionKey
        ) {
            return $plan->setReason('session_mismatch');
        }

        $plan
            ->setResumeAvailable(true)
            ->setStartFromTodoIndex(max(0, $runStateDto->getLastCompletedTodoIndex() + 1))
            ->setReason($runStateDto->getHistoryMessageCount() !== null ? 'ready' : 'history_missing');

        return $plan;
    }

    /**
     * Применяет откат истории по resume-плану, если в checkpoint есть `history_message_count`.
     *
     * @param ConfigurationAgent    $agentCfg Конфигурация агента.
     * @param TodoListResumePlanDto $plan     План resume.
     *
     * @return bool true, если откат был выполнен.
     */
    public static function applyHistoryRollback(ConfigurationAgent $agentCfg, TodoListResumePlanDto $plan): bool
    {
        $historyMessageCount = $plan->getHistoryMessageCount();
        if (!$plan->isResumeAvailable() || $historyMessageCount === null) {
            return false;
        }

        $agentCfg->resetChatHistory();
        $history = $agentCfg->getChatHistory();
        ChatHistoryTruncateHelper::truncateToMessageCount($history, $historyMessageCount);

        return true;
    }
}
