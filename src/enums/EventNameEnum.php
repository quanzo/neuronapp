<?php

declare(strict_types=1);

namespace app\modules\neuron\enums;

/**
 * Имена доменных событий приложения.
 *
 * Пример использования:
 * ```php
 * EventNameEnum::TODO_STARTED->value;
 * ```
 */
enum EventNameEnum: string
{
    case RUN_STARTED  = 'run.started';
    case RUN_FINISHED = 'run.finished';
    case RUN_FAILED   = 'run.failed';

    case TODO_STARTED        = 'todo.started';
    case TODO_COMPLETED      = 'todo.completed';
    case TODO_FAILED         = 'todo.failed';
    case TODO_GOTO_REQUESTED = 'todo.goto_requested';
    case TODO_GOTO_REJECTED  = 'todo.goto_rejected';
    case TODO_AGENT_SWITCHED = 'todo.agent_switched';

    case SKILL_STARTED   = 'skill.started';
    case SKILL_COMPLETED = 'skill.completed';
    case SKILL_FAILED    = 'skill.failed';

    case TOOL_STARTED   = 'tool.started';
    case TOOL_COMPLETED = 'tool.completed';
    case TOOL_FAILED    = 'tool.failed';

    case AGENT_MESSAGE_STARTED   = 'agent.message.started';
    case AGENT_MESSAGE_COMPLETED = 'agent.message.completed';
    case AGENT_MESSAGE_FAILED    = 'agent.message.failed';

    case ORCHESTRATOR_CYCLE_STARTED  = 'orchestrator.cycle_started';
    case ORCHESTRATOR_STEP_COMPLETED = 'orchestrator.step_completed';
    case ORCHESTRATOR_COMPLETED      = 'orchestrator.completed';
    case ORCHESTRATOR_FAILED         = 'orchestrator.failed';
    case ORCHESTRATOR_RESTARTED      = 'orchestrator.restarted';
    /** Resume без history_message_count в RunStateDto (возможны дубликаты сообщений). */
    case ORCHESTRATOR_RESUME_HISTORY_MISSING = 'orchestrator.resume_history_missing';

    /** Контекст инференса подготовлен и готов к отправке провайдеру. */
    case LLM_INFERENCE_PREPARED = 'llm.inference.prepared';
}
