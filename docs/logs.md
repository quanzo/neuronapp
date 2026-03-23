## Логирование и наблюдаемость

Документ описывает, как в проекте реализовано логирование (PSR‑3) и агрегированные логи запусков skills/todolist.

### Базовый файловый логгер (`FileLogger`)

Класс `FileLogger` (`src/classes/logger/FileLogger.php`) реализует `Psr\Log\LoggerInterface` и записывает сообщения в файл:

- файл открывается в режиме append;
- формат строки:
  - `[Y-m-d H:i:s] <level>: <message> <context_json>`;
  - `context` сериализуется в JSON (`JSON_UNESCAPED_UNICODE`);
  - объекты `Throwable` в контексте заменяются на структуру `{class, message}`.

Логгер обычно создаётся в `ConfigurationApp` и передаётся заинтересованным классам:

- `ConfigurationAgent` (через `LoggerAwareTrait` / `LoggerAwareContextualTrait`);
- консольные команды (через `AbstractAgentCommand::resolveFileLogger()`).

Директория логов выбирается через:

- `ConfigurationApp::getLogDirName()` → `'.logs'`;
- `ConfigurationApp::getLogDir()` → абсолютный путь к директории `.logs`, найденный через `DirPriority`.

Имя файла лога обычно включает sessionKey, чтобы можно было связать события с конкретной сессией.

### Агрегированные логи запусков (`RunLogger`)

Класс `RunLogger` (`src/classes/logger/RunLogger.php`) строит поверх любого PSR‑3‑логгера JSON‑подобные записи о запуске:

- `startRun(string $type, string $name, array $context = []): string`:
  - генерирует `runId` (случайный `bin2hex(random_bytes(16))`);
  - пишет событие `Run started` с полями:
    - `event = run_started`,
    - `runId`,
    - `type` (например, `todolist` или `skill`),
    - `name` (имя списка/навыка),
    - `timestamp` (в формате `DateTimeInterface::ATOM`),
    - + произвольный контекст (agent, session и др.).
- `finishRun(string $runId, array $metrics = [], ?Throwable $error = null): void`:
  - пишет событие завершения с полями:
    - `event = run_finished`,
    - `runId`,
    - `finishedAt` (ISO‑дата),
    - `success` (`true`/`false`),
    - + любые переданные метрики (`steps`, `toolCalls` и т.п.),
    - при ошибке — поле `error` с классом и сообщением исключения.

Интеграция:

- `Skill::execute()`:
  - вызывает `startRun('skill', $this->getName(), $context)`;
  - по завершении — `finishRun($runId, ['steps' => 1])` или `finishRun($runId, ['steps' => 0], $e)` при ошибке;
- `TodoList::execute()`:
  - вызывает `startRun('todolist', $this->getName(), $baseContext)`;
  - считает выполненные шаги (`$stepsExecuted`);
  - по завершении — `finishRun($runId, ['steps' => $stepsExecuted])`.

Эти записи помогают анализировать длительность и результаты запусков на уровне отдельных todolist/skills, а также коррелировать их с логами агентов.

### Что логируется в процессе работы

Основные компоненты используют logger для:

- ошибок конфигурации или выполнения (`error`, `critical`);
- диагностических сообщений о вызовах инструментов, skills, todolist;
- предупреждений при невозможности точного восстановления истории (`warning`);
- информационных сообщений о старте/завершении команд и операций (`info`).

Примеры:

- `ConfigurationAgent::sendMessageWithAttachments()`:
  - при исключении логирует `'Ошибка при отправке сообщения агенту'` с контекстом `{agent, session, exception}`.
- `Skill::execute()`:
  - `Skill started` и `Skill completed` с контекстом `{agent, session, skill}`.
- `TodoList::execute()`:
  - `TodoList started`, `Todo started`, `Todo completed`, `TodoList completed` с контекстом `{todolist, todo_index, ...}`.

Подробнее о том, как включается логгер и где создаются файлы, см. `docs/config.md`.

### Логирование payload LLM (system prompt + tools)

Для диагностики вызовов инструментов добавлено двухуровневое логирование LLM-запроса через `ConfigurationAgent`:

- событие `llm.inference.prepared`:
  - пишется в кастомном узле `LoggingChatNode` (`src/classes/neuron/nodes/LoggingChatNode.php`);
  - содержит `instructions_preview`, `instructions_length`, `tools_count`, `tools_names`, `tool_required_params`;
  - использует контекст `agent`/`session` через `ContextualLogger`.
- событие `llm.request.payload`:
  - пишется в декораторе `LoggingAIProviderDecorator` (`src/classes/neuron/providers/LoggingAIProviderDecorator.php`);
  - содержит информацию о подготовленном payload перед отправкой к провайдеру:
    - `provider_class`,
    - `system_present`, `system_length`, `system_preview`,
    - `messages_count`,
    - `tools_payload`,
    - `messages_payload` (только в `debug`-режиме).

Включение и режим:

- `ConfigurationAgent::$enableLlmPayloadLogging` — включает/выключает payload-логирование;
- `ConfigurationAgent::$llmPayloadLogMode` — режим `summary` или `debug`.

Безопасность логов:

- перед записью используется `LlmPayloadLogSanitizer` (`src/helpers/LlmPayloadLogSanitizer.php`);
- маскируются чувствительные ключи (`api_key`, `token`, `authorization`, `password`, `cookie` и т.д.);
- длинные строки обрезаются с маркером `...[truncated]`;
- ограничивается глубина рекурсивной сериализации (`[DEPTH_LIMIT_REACHED]`).