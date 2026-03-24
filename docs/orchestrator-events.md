# События оркестратора

Документ фиксирует события внешнего цикла `TodoListOrchestrator`
(`src/classes/orchestrators/TodoListOrchestrator.php`).

## События

- `orchestrator.cycle_started` — старт цикла (до `init` и `step`).
- `orchestrator.step_completed` — завершение итерации `step`.
- `orchestrator.completed` — успешное завершение по `completed=1`.
- `orchestrator.failed` — завершение по лимиту или ошибка выполнения.
- `orchestrator.restarted` — перезапуск после ошибки.

## Payload

Для payload используется `OrchestratorEventDto`
(`src/classes/dto/events/OrchestratorEventDto.php`).

Основные поля:

- `sessionKey` — ключ сессии.
- `timestamp` — время события (ATOM).
- `iterations` — число выполненных step-итераций.
- `restartCount` — число перезапусков.
- `completedRaw` — сырое значение `completed` из storage.
- `completedNormalized` — нормализованное значение `completed`.
- `reason` — код причины (`completed`, `max_iterations`, `error`, `restart_after_error`).
- `success` — признак успешного завершения.
- `errorClass`, `errorMessage` — информация об ошибке (если есть).

## Порядок для happy-path

1. `orchestrator.cycle_started`
2. `orchestrator.step_completed` (на каждой итерации)
3. `orchestrator.completed`

## Порядок для error-path с restart

1. `orchestrator.cycle_started`
2. `orchestrator.failed` (reason=`error`)
3. `orchestrator.restarted`
4. повторный цикл
