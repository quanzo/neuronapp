## Конфигурация приложения и агентов

Этот документ описывает `ConfigurationApp`, конфигурацию агентов (`ConfigurationAgent`) и файлы `config.jsonc` / `agents/*.php|jsonc`.

### `ConfigurationApp` и `config.jsonc`

Класс `ConfigurationApp` (`src/classes/config/ConfigurationApp.php`) — синглтон, отвечающий за:

- загрузку настроек из `config.jsonc` (через `DirPriority`);
- хранение приоритетных директорий (`DirPriority`);
- создание producers:
  - `getAgentProducer()` → `AgentProducer`;
  - `getTodoListProducer()` → `TodoListProducer`;
  - `getSkillProducer()` → `SkillProducer`;
- получение элементов:
  - `getAgent(string $name): ?ConfigurationAgent`;
  - `getTodoList(string $name): ?TodoList`;
  - `getSkill(string $name): ?Skill`;
- управление ключом сессии:
  - `buildSessionKey()` генерирует базовый ключ вида `YYYYMMDD-HHMMSS-μs`;
  - `getSessionKey()` — ленивое получение/создание ключа (опционально с `userId`);
  - `setSessionKey(string $sessionKey)` — установка ключа (например, из CLI) без проверки наличия истории;
  - `isValidSessionKey(string $sessionKey): bool` — валидация формата;
  - `sessionExists(string $sessionKey, ?string $agentName = null): bool` — проверка файла истории (`neuron_<key>.chat`) через `DirPriority`;
- путь к служебным директориям:
  - `getSessionDirName()` / `getSessionDir()` → `.sessions`;
  - `getLogDirName()` / `getLogDir()` → `.logs`;
  - `getStoreDirName()` / `getStoreDir()` → `.store` (чекпоинты выполнения todolist);
  - `getVarStorage()` — объектное хранилище результатов (`VarStorage`) для директории `.store`;
- доступ к настройкам:
  - `getAll(): array<string,mixed>`;
  - `get(string $key, mixed $default = null): mixed` — поддерживает ключи вида `"context_files.allowed_paths"`;
  - `getLogContext()` — контекст логирования уровня приложения (содержит `session`).

Файл `config.jsonc`:

- хранится в приоритетных директориях (`APP_START_DIR`, `APP_WORK_DIR`);
- может содержать разделы:
  - общие настройки (например, `userId`);
  - `context_files` — управление подключением файлов по @‑ссылкам (см. `docs/directories.md`, `docs/files.md`);
  - любые дополнительные опции, которые читаются через `ConfigurationApp::get()`.

### Опции оркестратора (`TodoListOrchestrator`)

Оркестратор (`src/classes/orchestrators/TodoListOrchestrator.php`) поддерживает опциональное поведение
«суммаризация истории step-шага (каждой итерации step-цикла)» через Skill.

Реализация суммаризации вынесена в сервис `SummarizeService`
(`src/classes/neuron/summarize/SummarizeService.php`).

Ключи `config.jsonc`:

- `orchestrator.step_history_summarize.enabled` (bool, default=`false`) — включить/выключить поведение.
- `orchestrator.step_history_summarize.skill` (string) — имя skill, который будет вызван для суммаризации
  (разрешается через `ConfigurationApp::getSkill()`).
- `orchestrator.step_history_summarize.use_skill` (bool, default=`false`) — использовать ли LLM-skill для суммаризации:
  - `false`: в историю будет вставляться `transcript` (после фильтрации) как “суммаризованный” контент;
  - `true`: будет вызван указанный `skill`, а в историю вставится ответ skill.
- `orchestrator.step_history_summarize.mode` (string, default=`replace_range`) — как применять summary:
  - `replace_range`: заменить сообщения **текущего шага** одним summary-сообщением;
  - `append_summary`: не удалять сообщения шага, а добавить summary отдельным сообщением после шага.
- `orchestrator.step_history_summarize.role` (string, default=`assistant`) — роль summary-сообщения:
  - `assistant` или `system`.
- `orchestrator.step_history_summarize.min_transcript_chars` (int, default=`50`) — минимальная длина transcript
  (после фильтрации) для запуска суммаризации.
- `orchestrator.step_history_summarize.debug` (bool, default=`false`) — включить подробное логирование skip/apply.
- `orchestrator.step_history_summarize.filter.*` — фильтрация «шума» перед суммаризацией:
  - `filter.tool_messages` (bool, default=`true`) — исключать tool-call/tool-result;
  - `filter.history_tools` (bool, default=`true`) — исключать сообщения инструментов `chat_history.*`;
  - `filter.min_message_chars` (int, default=`3`) — исключать слишком короткие сообщения;
  - `filter.dedup_consecutive` (bool, default=`true`) — убирать подряд повторяющиеся сообщения.

Поведение:

- на каждой итерации step-цикла перед выполнением шага снимается размер истории;
- после выполнения шага копируются сообщения, добавленные **только этим шагом**;
- сообщения шага фильтруются от «шума» и преобразуются в `transcript`;
- при построении `transcript` дополнительно:
  - исключаются пустые сообщения;
  - исключаются дубликаты контента (role+content) в рамках одного transcript;
  - исключаются служебные реплики проверки статуса из `LlmCycleHelper` (`MSG_CHECK_WORK`, `MSG_CHECK_WORK2`, `MSG_RESULT`).
- если `transcript` слишком короткий — суммаризация пропускается;
- иначе вызывается skill с параметром `transcript` (строка), а результат применяется по `mode`.

Пример:

```jsonc
{
  "orchestrator": {
    "step_history_summarize": {
      "enabled": true,
      "skill": "summarize/step_history",
      "use_skill": true,
      "mode": "replace_range",
      "role": "assistant",
      "min_transcript_chars": 50,
      "debug": false,
      "filter": {
        "tool_messages": true,
        "history_tools": true,
        "min_message_chars": 3,
        "dedup_consecutive": true
      }
    }
  }
}
```

### Конфигурация агента (`ConfigurationAgent`)

Класс `ConfigurationAgent` (`src/classes/config/ConfigurationAgent.php`) представляет отдельного агента LLM:

- основные свойства:
  - `agentName` — имя агента (совпадает с именем файла конфигурации без расширения);
  - `enableChatHistory` — использовать ли файловую историю (`FileFullChatHistory`) или `InMemoryChatHistory`;
  - `contextWindow` — размер контекстного окна LLM;
  - `provider` — конфигурация провайдера (`AIProviderInterface` или `callable`);
  - `instructions` — системный промпт (строка, `Stringable` или `callable`);
  - `tools` — базовый набор инструментов (`ToolInterface`/`ToolkitInterface` и пр.);
  - `toolMaxTries` — лимит попыток использования инструмента;
  - `enableLlmPayloadLogging` — включение диагностического логирования payload к LLM;
  - `llmPayloadLogMode` — режим payload-логов: `summary` (без payload сообщений) или `debug` (с payload сообщений);
  - `mcp` — конфигурация MCP‑коннекторов;
  - `embeddingProvider`, `vectorStore`, `preProcessors`, `postProcessors` — для RAG;
  - `sessionKey` — базовый ключ сессии (без имени агента), синхронизируется с `ConfigurationApp::getSessionKey()`;
- история чата:
  - `getChatHistory()` создаёт:
    - при `enableChatHistory === true` — `FileFullChatHistory` в директории `ConfigurationApp::getSessionDir()`, с префиксом `neuron_` и расширением `.chat`;
    - при `enableChatHistory === false` — `InMemoryChatHistory`;
  - `setChatHistory()` и `resetChatHistory()` управляют текущим объектом истории;
- сессии todolist:
  - `getBlankRunStateDto()` / `getExistRunStateDto()` — работа с `RunStateDto` и чекпоинтами в `.store` через `RunStateCheckpointHelper`;
- результаты:
  - `getVarStorage()` — доступ к хранилищу результатов (`VarStorage`), которое используют инструменты семейства `Var*Tool`;
- клоны для отдельных запусков:
  - `cloneForSession(ChatHistoryCloneMode $mode)` — создаёт клон с:
    - сброшенным агентом (`_agent = null`);
    - новой in‑memory историей (пустой или с копией контекста);
    - тем же `sessionKey`.

Экземпляры агентов создаются производителем `AgentProducer` и используются консольными командами, skills и todolist.

При включённом `enableLlmPayloadLogging` провайдер автоматически оборачивается в `LoggingAIProviderDecorator`, а узел чата заменяется на `LoggingChatNode`, поэтому в обычный файловый лог попадают события:

- `llm.inference.prepared` — что агент подготовил перед отправкой (`instructions`, tools summary);
- `llm.request.payload` — что было сериализовано в payload к провайдеру (`system`, `messages`, `tools`).

### Файлы агентов (`agents/*.php`, `agents/*.jsonc`)

Файлы агентов хранятся в директории `agents/` (через `DirPriority`). За поиск и создание конфигураций отвечает `AgentProducer`:

- `getStorageDirName()` → `'agents'`;
- расширения: `['php', 'jsonc']` (PHP имеет приоритет при совпадении имён файлов);
- `createFromFile(string $path, string $name): ?ConfigurationAgent`:
  - вызывает `ConfigurationAgent::makeFromFile()` для чтения конфигурации;
  - выставляет `agentName` в имя файла;
  - при `enableChatHistory === false` сбрасывает историю (`resetChatHistory()`);
  - при отсутствии логгера устанавливает `FileLogger` с контекстом.

Метод `ConfigurationAgent::makeFromFile()`:

- поддерживает `.php` (возвращает массив настроек из файла) и `.jsonc`/`.json` (после очистки комментариев через `CommentsHelper::stripComments()`);
- делегирует создание объекта в `makeFromArray(array $cfg, ConfigurationApp $configApp)`, который:
  - заполняет поля конфигурации по известным ключам;
  - устанавливает `logger`, `configurationApp`, `sessionKey` (через `configApp->getSessionKey()`).

Файлы примеров конфигураций агентов находятся в `testapp/agents`.

### Связь конфигурации с остальными компонентами

- Skills и todolist:
  - получают агента по имени через `ConfigurationApp::getAgent($name)`;
  - могут переопределять агента через опцию `agent` в YAML‑шапке (`AbstractPromptWithParams::getConfigurationAgent()`).
- Консольные команды:
  - `simplemessage` и `todolist` используют `ConfigurationApp` для разрешения агента и, при необходимости, установки `sessionKey` из `--session_id`;
  - логгер для команд настраивается через `AbstractAgentCommand::resolveFileLogger()`, который использует `ConfigurationApp::getLogDir()`.

Подробнее о командах см. `docs/console.md`, о skills и todolist — в `docs/skills.md` и `docs/todolist.md`.

### Управление сессиями (SessionConfigAppService)

Для управления историей сессий и статусами выполнения run используется сервис:

- `SessionConfigAppService` (`src/services/config/SessionConfigAppService.php`)

Он предоставляет API для:

- списка сессий по файлам `.sessions/neuron_*.chat`;
- чтения/удаления истории сессии;
- получения статуса выполнения TodoList по чекпоинту `RunStateDto` в `.store`;
- операций над сообщениями (удаление/вставка по индексу в полной истории);
- получения копии истории, обрезанной произвольным `HistoryTrimmerInterface`.

Подробности и примеры: `docs/sessions.md`.