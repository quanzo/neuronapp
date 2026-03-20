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
  - `getIntermediateStorage()` — объектное хранилище промежуточных результатов (`IntermediateStorage`) для директории `.store`;
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
- промежуточные результаты:
  - `getIntermediateStorage()` — доступ к хранилищу промежуточных результатов (`IntermediateStorage`), которое используют инструменты семейства `Intermediate*Tool`;
- клоны для отдельных запусков:
  - `cloneForSession(ChatHistoryCloneMode $mode)` — создаёт клон с:
    - сброшенным агентом (`_agent = null`);
    - новой in‑memory историей (пустой или с копией контекста);
    - тем же `sessionKey`.

Экземпляры агентов создаются производителем `AgentProducer` и используются консольными командами, skills и todolist.

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