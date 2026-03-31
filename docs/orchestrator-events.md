# События оркестратора

Документ фиксирует события внешнего цикла `TodoListOrchestrator`
(`src/classes/orchestrators/TodoListOrchestrator.php`).

При подключении `OrchestratorLoggingSubscriber` (см. `AbstractAgentCommand::resolveFileLogger()`)
все перечисленные ниже события дополнительно пишутся в PSR-3 лог сессии.

## Resume по RunStateDto

Перед каждым вызовом `TodoList::execute()` для списков `init` / `step` / `finish` оркестратор
вычисляет `startFromTodoIndex` по чекпоинту в `.store` (тот же контракт, что у `todolist --resume`):

- читается `RunStateDto` через `ConfigurationAgent::getExistRunStateDto()`;
- resume применяется только если `todolist_name` совпадает с `TodoList::getName()` текущего списка,
  `finished === false`, и `session_key` в DTO совпадает с ключом сессии `ConfigurationApp` (если в DTO ключ непустой);
- при совпадении история чата усекается до `history_message_count` (если задано), индекс старта =
  `last_completed_todo_index + 1`.

Это не отменяет внешнюю логику по флагу `completed` в `IntermediateStorage`. Переходы `todo_goto`
остаются внутренним механизмом одного запуска `TodoList::execute()`.

## Опциональная суммаризация step-истории

Оркестратор может (по фиче-флагу в `config.jsonc`) на **каждой итерации** step-цикла:

- снять диапазон сообщений, добавленных именно во время step;
- передать их в skill суммаризации;
- применить результат как summary шага (заменить сообщения шага или добавить summary отдельно — по режиму).

Ключи и пример конфигурации см. в `docs/config.md` (раздел «Опции оркестратора»).

## События

- `orchestrator.resume_history_missing` — при resume списка в оркестраторе в `RunStateDto` нет `history_message_count` (возможны дубликаты сообщений); payload: `OrchestratorResumeHistoryMissingEventDto`; логирование через `OrchestratorLoggingSubscriber`.
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

## Тесты (`OrchestratorSpyProvider`)

В `tests/Support/OrchestratorSpyProvider.php` счётчик `stepCalls` увеличивается на **каждый** вызов
`chat()` с пользовательским текстом, содержащим `STEP`. Внутри одного `sendMessage` ошибки LLM
повторяются через `WaitSuccess` (`maxLlmAttempts = 5` в `ConfigurationAgent`). Чтобы смоделировать
**исчерпание** ретраев и довести исключение до оркестратора, в сценариях нужно задавать сбой на
индексах `[1, 2, 3, 4, 5]` — иначе на четвёртом вызове `stepCalls` уже равен `4` и не попадает в
короткий список `[1, 2, 3]`, ответ считается успешным и рестарт не происходит.

Для быстрых циклов `LlmCycleHelper::waitCycle` в шпионе отдельно обрабатывается текст проверки
(`Have you completed the all current task`), чтобы не расходовать `maxTotalRounds` на «Unclear».
