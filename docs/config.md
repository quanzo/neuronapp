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
  - `buildSessionKey()` генерирует **базовую** часть ключа вида `YYYYMMDD-HHMMSS-μs`;
  - `getSessionKey()` — ленивое получение/создание **полного** ключа с `userId`;
  - `setSessionKey(string $sessionKey)` — установка ключа (например, из CLI) без проверки наличия истории;
  - `isValidSessionKey(string $sessionKey): bool` — валидация формата;
  - `describeSessionKeyFormat(): string` — человекочитаемое описание формата для CLI и docs;
  - `sessionExists(string $sessionKey, ?string $agentName = null): bool` — проверка файла истории (`neuron_<key>.chat`) через `DirPriority`;
- путь к служебным директориям:
  - `getSessionDirName()` / `getSessionDir()` → `.sessions`;
  - `getLogDirName()` / `getLogDir()` → `.logs`;
  - `getStoreDirName()` / `getStoreDir()` → `.store` (чекпоинты выполнения todolist);
  - `getVarStorage()` — объектное хранилище результатов (`VarStorage`) для директории `.store`;
- директории и базовые пути:
  - `getStartDir()` — директория старта приложения (самая приоритетная базовая директория в `DirPriority`, обычно `getcwd()` на момент запуска `bin/console.php`);
- доступ к настройкам:
  - `getAll(): array<string,mixed>`;
  - `get(string $key, mixed $default = null): mixed` — поддерживает ключи вида `"context_files.allowed_paths"`;
  - `isLongTermMindCollectionEnabled(): bool` — читает `mind.collect` из `config.jsonc` (по умолчанию `true`); при `false` подписчик `LongTermMindSubscriber` не пишет в `.mind` (см. `docs/mind.md`);
  - `getLogContext()` — контекст логирования уровня приложения (содержит `session`).

Файл `config.jsonc`:

- хранится в приоритетных директориях (`APP_START_DIR`, `APP_WORK_DIR`);
- может содержать разделы:
  - общие настройки (например, `userId`);
  - `context_files` — управление подключением файлов по @‑ссылкам (см. `docs/directories.md`, `docs/files.md`);
  - любые дополнительные опции, которые читаются через `ConfigurationApp::get()`.

#### Формат `session_id` / `sessionKey`

Source of truth:

- `src/helpers/SessionKeyHelper.php`
- `ConfigurationApp::SESSION_KEY_PATTERN`
- `ConfigurationApp::describeSessionKeyFormat()`

Канонические правила:

- **базовая часть** ключа: `YYYYMMDD-HHMMSS-uuuuuu`;
- **полный** ключ сессии: `YYYYMMDD-HHMMSS-uuuuuu-userId`;
- пример полного значения: `20250301-143022-123456-0`;
- CLI и документация не должны дублировать regex вручную, а должны ссылаться на `SessionKeyHelper`.

Инварианты:

- `ConfigurationApp::buildSessionKey()` возвращает только базовую часть без `userId`;
- `ConfigurationApp::getSessionKey()` возвращает полный ключ, добавляя `userId` или `0`;
- `ConfigurationApp::isValidSessionKey()` принимает только полный ключ, пригодный для CLI и файловых артефактов.

Edge cases:

- строка без `userId` не считается валидным `session_id` для CLI;
- пустой `userId` нормализуется в `0`;
- буквенный `userId` не допускается.

### Конфигурация агента (`ConfigurationAgent`)

Класс `ConfigurationAgent` (`src/classes/config/ConfigurationAgent.php`) представляет отдельного агента LLM:

- основные свойства:
  - `agentName` — имя агента (совпадает с именем файла конфигурации без расширения);
  - `enableChatHistory` — использовать ли файловую историю (`FileFullChatHistory`) или `InMemoryChatHistory`;
  - `contextWindow` — размер контекстного окна LLM;
  - `provider` — конфигурация провайдера (`AIProviderInterface` или `callable`);
  - `instructions` — системный промпт (строка, `Stringable` или `callable`);
  - `useAgentsFile` — подключать ли содержимое `AGENTS.md` к системному промпту (по умолчанию `false`).\n    Файл ищется через `ConfigurationApp->getDirPriority()->resolveFile('AGENTS.md')` и **добавляется в конец** системного промпта;
  - `tools` — базовый набор инструментов (`ToolInterface`/`ToolkitInterface` и пр.);
  - `skills` — список skills, которые будут подключены как tools для этого агента;
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
  - триммер окна для LLM выбирается через настройки `config.jsonc`:
    - `history.trimmer = "fluid"` (по умолчанию) — окно строится от хвоста истории по токенам (`FluidContextWindowTrimmer`);
    - `history.trimmer = "ccl_compact"` — режим «CCL Code»: microcompact старых tool-result + LLM-summary головы (`CclCodeHistoryTrimmer`).

#### Настройки `history.*` для триммера

Ключи в `config.jsonc`:

- `history.trimmer` (string): `"fluid"` | `"ccl_compact"`.
- `history.ccl_compact.tail_ratio` (float, default `0.6`): доля контекстного окна, выделяемая под tail (хвост), который сохраняется без изменений.
- `history.ccl_compact.keep_recent_tool_results` (int, default `10`): сколько последних `ToolResultMessage` оставлять «как есть» (старые tool-result очищаются маркером).

Пример:

```json
{
  "history": {
    "trimmer": "ccl_compact",
    "ccl_compact": {
      "tail_ratio": 0.6,
      "keep_recent_tool_results": 10
    }
  }
}
```
- сессии todolist:
  - `getBlankRunStateDto()` / `getExistRunStateDto()` — работа с `RunStateDto` и чекпоинтами в `.store` через `RunStateCheckpointHelper`;
  - `resumeRunState()` — применяет rollback истории через `TodoListResumeHelper`, если checkpoint содержит `history_message_count`;
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
- расширения: `AgentProducer::EXTENSIONS = ['php', 'jsonc']` (PHP имеет приоритет при совпадении имён файлов);
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

#### `skills` в конфигурации агента

`skills` — это **массив строк** с именами skills, которые будут подключены к агенту как LLM tools (через `Skill::getTool()`).

Пример (PHP):

```php
return [
    // ...
    'skills' => [
        'review/summary',
        'review/checklist',
    ],
];
```

Пример (JSONC):

```json
{
  "skills": ["review/summary", "review/checklist"]
}
```

Файлы примеров конфигураций агентов находятся в `testapp/agents`.

### Связь конфигурации с остальными компонентами

- Skills и todolist:
  - получают агента по имени через `ConfigurationApp::getAgent($name)`;
  - могут переопределять агента через опцию `agent` в YAML‑шапке (`AbstractPromptWithParams::getConfigurationAgent()`).
- Консольные команды:
  - `simplemessage` и `todolist` используют `ConfigurationApp` для разрешения агента и, при необходимости, установки `sessionKey` из `--session_id`;
  - логгер для команд настраивается через `AbstractAgentCommand::resolveFileLogger()`, который использует `ConfigurationApp::getLogDir()`.

Подробнее о командах см. `docs/console.md`, о skills и todolist — в `docs/skills.md` и `docs/todolist.md`.

### Имена файлов сессий, чекпоинтов и логов

Source of truth:

- `src/helpers/StorageFileHelper.php`
- `src/helpers/RunStateCheckpointHelper.php`
- `src/classes/storage/VarStorage.php`

Канонические шаблоны:

- история сессии: `.sessions/neuron_<sessionKey>.chat`
- история с именем агента: `.sessions/neuron_<sessionKey>-<agentName>.chat`
- checkpoint run-state: `.store/run_state_<sessionKey>_<agentName>.json`
- результат VarStorage: `.store/var_<sessionKey>_<name>.json`
- индекс VarStorage: `.store/var_index_<sessionKey>.json`
- лог команды: `.logs/<sessionKey>.log`

Инварианты:

- все части имени файла проходят через `StorageFileHelper::sanitizeFileKeyPart()`;
- ручное построение имён файлов в бизнес-логике считается расхождением и должно заменяться вызовом helper;
- `RunStateCheckpointHelper` и `VarStorage` обязаны использовать те же шаблоны, что документированы здесь.

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