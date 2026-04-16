<?php

declare(strict_types=1);

namespace Tests\Support;

use app\modules\neuron\classes\dto\orchestrator\OrchestratorResultDto;
use app\modules\neuron\classes\orchestrators\TodoListOrchestrator;
use app\modules\neuron\classes\todo\TodoList;

/**
 * Тестовый наследник, открывающий protected API оркестратора.
 */
final class TestableTodoListOrchestrator extends TodoListOrchestrator
{
    public int $completeCalls = 0;
    public int $failCalls = 0;

    /**
     * Прокси к {@see TodoListOrchestrator::resolveStartFromTodoIndexForTodoList()} для тестов.
     */
    public function resolveStartFromTodoIndexProxy(TodoList $todoList): int
    {
        return $this->resolveStartFromTodoIndexForTodoList($todoList);
    }

    protected function onComplete(OrchestratorResultDto $result): void
    {
        $this->completeCalls++;
        parent::onComplete($result);
    }

    protected function onFail(\Throwable|string $reason, ?OrchestratorResultDto $result = null): void
    {
        $this->failCalls++;
        parent::onFail($reason, $result);
    }
}
