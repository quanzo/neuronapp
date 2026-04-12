# Шина событий `EventBus`

Документ описывает поведение синхронной шины событий `EventBus` (`src/classes/events/EventBus.php`).

## Назначение

Класс хранит обработчики в памяти процесса и позволяет:

- подписывать обработчик на событие через `EventBus::on()`;
- отписывать обработчик через `EventBus::off()`;
- вызывать событие через `EventBus::trigger()`;
- очищать подписки через `EventBus::clear()`.

## Контекстный ключ события

Для подписки и вызова используется контекстный ключ:

- `*` — глобальный ключ события;
- `class-string` — ключ конкретного класса (`class:<FQCN>`);
- `object` — ключ конкретного объекта (`object:<id>` через `spl_object_id`).

Правила:

- в `on()` / `off()` передача объекта привязывает обработчик строго к конкретному экземпляру;
- в `on()` / `off()` передача строки привязывает обработчик к точному имени класса;
- в `trigger()` для объекта выполняются обработчики в порядке:
  - `object:<id>` -> `class:<объект>` -> `class:<родители>` -> `*`;
- в `trigger()` для имени класса выполняются:
  - `class:<FQCN>` -> `*`.

## Остановка цепочки обработчиков

При вызове события каждый обработчик получает payload.
Если обработчик возвращает `false`, дальнейшие обработчики для найденного контекстного ключа не выполняются.

Обработчик вызывается с двумя аргументами:

- `payload` (данные события);
- `initiator` (второй аргумент `$_class` из `EventBus::trigger()`).

Если callback объявлен только с одним аргументом (payload), это не является ошибкой.

## Очистка подписок

- `EventBus::clear()` без аргументов удаляет все зарегистрированные обработчики.
- `EventBus::clear('event.name')` удаляет подписки только указанного события.

Эта операция полезна в тестах и long-running процессах для изоляции состояния.

## Имена событий

Для имен событий используется enum `EventNameEnum` (`src/enums/EventNameEnum.php`).

## Иерархия Event DTO

Все DTO событий наследуются от `BaseEventDto` (`src/classes/dto/events/BaseEventDto.php`).
Нормальные (success) события и ошибочные (error/failed) события разделены в разные классы.
Ошибочные DTO реализуют интерфейс `IErrorEvent` (`src/interfaces/IErrorEvent.php`) и
добавляют поля `errorClass`, `errorMessage` через трейт `HasErrorInfoTrait` (`src/traits/HasErrorInfoTrait.php`).

Любое ошибочное событие можно распознать единообразно через `$event instanceof IErrorEvent`.

```
BaseEventDto (implements IArrayable, Stringable)
│   sessionKey, runId, timestamp, agent
│
├── RunEventDto  (type, name, steps)
│   └── RunErrorEventDto  (implements IErrorEvent; +errorClass, +errorMessage)
│
├── TodoEventDto  (todoListName, todoIndex, todo, todoAgent, gotoTargetIndex, gotoTransitionsCount, reason)
│   └── TodoErrorEventDto  (implements IErrorEvent; +errorClass, +errorMessage)
│       └── TodoGotoRejectedEventDto
│
├── SkillEventDto  (skill, params)
│   └── SkillErrorEventDto  (implements IErrorEvent; +errorClass, +errorMessage)
│
├── ToolEventDto  (toolName)
│   └── ToolErrorEventDto  (implements IErrorEvent; +errorClass, +errorMessage)
│
├── OrchestratorEventDto  (iterations, restartCount, completedNormalized, completedRaw, reason)
│   ├── OrchestratorErrorEventDto  (implements IErrorEvent; +errorClass, +errorMessage)
│   └── OrchestratorResumeHistoryMissingEventDto  (+todolistName, +lastCompletedTodoIndex, +startFromTodoIndex)
│
├── AgentMessageEventDto  (outgoingMessage?, incomingMessage?, attachmentsCount, structured, durationSeconds)
│   └── AgentMessageErrorEventDto  (implements IErrorEvent; +errorClass, +errorMessage; исходящее сообщение в родительских полях)
│
├── LlmInferenceEventDto  (toolsCount, toolsNames, toolRequiredParams, instructionsPreview, instructionsLength, userMessagePreview, userMessageLength, messagesCount?, messagesSanitized?)
```

### Принцип разделения normal/error

- **Нормальные DTO** (`*EventDto`) — для событий `*.started`, `*.completed`, `*.finished`.
  Не содержат полей ошибки.
- **Ошибочные DTO** (`*ErrorEventDto`) — для событий `*.failed`.
  Наследуются от нормального DTO, реализуют `IErrorEvent`, добавляют `errorClass`/`errorMessage`.
- **Специальные ошибочные DTO** (`TodoGotoRejectedEventDto`) — для конкретных типов ошибок,
  наследуются от базового ошибочного DTO домена.

### IErrorEvent и HasErrorInfoTrait

Интерфейс `IErrorEvent` (`src/interfaces/IErrorEvent.php`) объединяет все ошибочные DTO
и позволяет распознавать их через `$event instanceof IErrorEvent`.

Трейт `HasErrorInfoTrait` (`src/traits/HasErrorInfoTrait.php`) предоставляет реализацию `IErrorEvent`:
- `getErrorClass()` / `setErrorClass()` — FQCN класса исключения;
- `getErrorMessage()` / `setErrorMessage()` — текст ошибки;
- `errorInfoToArray()` — массив для слияния с `toArray()`;
- `buildErrorStringParts()` — пары key=value для строкового представления.

## Stringable

Все event DTO реализуют `Stringable` через `BaseEventDto`.
При приведении к строке возвращается человекочитаемое сообщение, пригодное для парсинга:

```
[RunEvent] type=todolist | name=inline_message | steps=0 | runId=aaf9e381... | agent=agent-main | ts=2026-04-09T15:50:42+00:00
[RunErrorEvent] type=todolist | name=inline_message | steps=0 | error=RuntimeException: "timeout" | runId=aaf9e381... | agent=agent-main | ts=...
[SkillEvent] skill=skill-file-block-summarize | params={"startLine":0,"path":"temp/file.txt"} | runId=0230db... | agent=agent-main | ts=...
[TodoEvent] list=inline_message | index=0 | todo="Какие у тебя есть инструменты..." | todoAgent=agent-main | runId=... | agent=agent-main | ts=...
[ToolErrorEvent] tool=chunk_view | error=IOException: "file not found" | runId= | agent=agent-main | ts=...
```

Формат:
- `[Tag]` — короткое имя класса без суффикса `Dto` (напр. `RunEvent`, `SkillErrorEvent`);
- `key=value` пары, разделённые ` | `;
- Значения с пробелами/спецсимволами экранируются кавычками;
- Домен-специфичные поля идут первыми, базовые (runId, agent, ts) — в конце.

## Каталог событий

### Run
- `run.started` — начало run (payload: `RunEventDto`)
- `run.finished` — успешное завершение run (payload: `RunEventDto`)
- `run.failed` — ошибка run (payload: `RunErrorEventDto`)

### Todo
- `todo.started` — начало пункта (payload: `TodoEventDto`)
- `todo.completed` — завершение пункта (payload: `TodoEventDto`)
- `todo.failed` — ошибка пункта (payload: `TodoErrorEventDto`)
- `todo.goto_requested` — запрос goto (payload: `TodoEventDto`)
- `todo.goto_rejected` — отклонение goto (payload: `TodoGotoRejectedEventDto`)
- `todo.agent_switched` — переключение агента (payload: `TodoEventDto`)

### Skill
- `skill.started` — начало навыка (payload: `SkillEventDto`)
- `skill.completed` — завершение навыка (payload: `SkillEventDto`)
- `skill.failed` — ошибка навыка (payload: `SkillErrorEventDto`)

### Tool
- `tool.started` — начало инструмента (payload: `ToolEventDto`)
- `tool.completed` — завершение инструмента (payload: `ToolEventDto`)
- `tool.failed` — ошибка инструмента (payload: `ToolErrorEventDto`)

### Agent Message
- `agent.message.started` — начало запроса к LLM (payload: `AgentMessageEventDto` с заполненным `outgoingMessage`)
- `agent.message.completed` — успешный шаг отправки (payload: `AgentMessageEventDto` с `outgoingMessage` и опционально `incomingMessage` — ответ ассистента или null при structured без сообщения / после wait-cycle без ответа)
- `agent.message.failed` — ошибка запроса к LLM (payload: `AgentMessageErrorEventDto` с `outgoingMessage` и полями ошибки)

### Orchestrator
- `orchestrator.cycle_started` — начало цикла (payload: `OrchestratorEventDto`)
- `orchestrator.step_completed` — завершение шага (payload: `OrchestratorEventDto`)
- `orchestrator.completed` — успешное завершение (payload: `OrchestratorEventDto`)
- `orchestrator.failed` — ошибка (payload: `OrchestratorErrorEventDto`)
- `orchestrator.restarted` — рестарт после ошибки (payload: `OrchestratorErrorEventDto`)
- `orchestrator.resume_history_missing` — resume без history_message_count (payload: `OrchestratorResumeHistoryMissingEventDto`)

### LLM Inference
- `llm.inference.prepared` — контекст инференса подготовлен и готов к отправке провайдеру (payload: `LlmInferenceEventDto`)

## Подписчики

Подписчики находятся в `src/classes/events/subscribers`.

Текущие подписчики:

- `RunLoggingSubscriber` — логирует `run.*` события в PSR-3 логгер.
- `ToolLoggingSubscriber` — логирует `tool.*` события в PSR-3 логгер.
- `SkillLoggingSubscriber` — логирует `skill.*` события в PSR-3 логгер.
- `TodoListLoggingSubscriber` — логирует `todo.*` события в PSR-3 логгер.
- `OrchestratorLoggingSubscriber` — логирует события `orchestrator.*` из `TodoListOrchestrator` в PSR-3 логгер.
- `LlmInferenceLoggingSubscriber` — логирует `llm.inference.prepared` в PSR-3 логгер (уровень `info`).
- `LongTermMindSubscriber` — пишет `agent.message.completed` в файлы `.mind` (см. `docs/mind.md`).

Подписчики используют `(string) $payload` как сообщение PSR-3 и `$payload->toArray()` как контекст.
Формат сообщения: `"<Domain> event: <action> | [<Tag>] key=value | key=value | ..."`.

## Поле agentName в DTO

Базовый `BaseEventDto` содержит поле `agentName`.

- В самом DTO хранится ссылка на объект `ConfigurationAgent` (если доступен).
- В `toArray()` выводится только имя агента (`agentName`).
- Полный объект конфигурации агента (`agent cfg`) в payload не сериализуется.

Регистрация подписчиков выполняется напрямую в `AbstractAgentCommand::resolveFileLogger()`.
