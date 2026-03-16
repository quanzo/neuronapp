## Иерархия директорий и окружения

Этот документ описывает, как проект ищет файлы конфигураций, агентов, skills и todolist через `DirPriority`, и как использовать альтернативные рабочие директории (`APP_START_DIR` / `APP_WORK_DIR`).

### Базовые понятия

- **`APP_START_DIR`** — директория, из которой запускается `bin/console` (стартовая точка).
- **`APP_WORK_DIR`** — рабочая директория приложения (например, `testapp` или `testapp2`), задаётся переменной окружения и используется для изолированных окружений.
- **`DirPriority`** — класс, инкапсулирующий приоритетный список директорий для поиска файлов и поддиректорий.

При инициализации приложения создаётся объект `DirPriority` с двумя директориями:

```php
new DirPriority([APP_START_DIR, APP_WORK_DIR]);
```

Именно этот объект передаётся в `ConfigurationApp::init()` и далее используется всеми producers.

### Класс `DirPriority`

Реализация находится в `src/classes/dir/DirPriority.php` и обеспечивает:

- проверку существования директорий в конструкторе;
- метод `resolveFile(string $relFileName, ?array $extensions = null): ?string`:
  - перебирает базовые директории по приоритету;
  - при указанных расширениях пробует `<name>.<ext>` в порядке списка (`php`, `jsonc`, `md`, `txt` и т.д.);
  - возвращает абсолютный путь к первому найденному файлу или `null`;
- метод `resolveDir(string $relDirPath): ?string`:
  - ищет поддиректорию `<base>/<relDirPath>` в приоритетном порядке;
  - возвращает первый существующий путь или `null`.

Именно через `DirPriority` ищутся:

- конфиг `config.jsonc`;
- директории `agents/`, `skills/`, `todos/`;
- служебные папки `.sessions`, `.logs`, `.store`;
- файлы по @‑ссылкам в тексте skills/todos (см. `AttachmentHelper::buildContextAttachments()`).

### `ConfigurationApp` и расположение файлов

Класс `ConfigurationApp` (`src/classes/config/ConfigurationApp.php`) использует `DirPriority` для:

- загрузки `config.jsonc` из приоритетных директорий;
- разрешения путей:
  - `getSessionDir()` → `.sessions`;
  - `getLogDir()` → `.logs`;
  - `getStoreDir()` → `.store` (чекпоинты выполнения todolist);
- создания producers:
  - `AgentProducer` (папка `agents/`);
  - `TodoListProducer` (папка `todos/`);
  - `SkillProducer` (папка `skills/`).

Producers сами отвечают за расширения файлов:

- `AgentProducer` — `agents/<name>.php` или `agents/<name>.jsonc` (с приоритетом PHP);
- `TodoListProducer` — `todos/<name>.txt` или `todos/<name>.md`;
- `SkillProducer` — `skills/<name>.txt` или `skills/<name>.md`.

### Альтернативные окружения (`testapp2` и др.)

Альтернативное рабочее окружение задаётся через `APP_WORK_DIR` и может иметь собственный набор конфигов, агентов, skills и todolist.

Пример структуры для `testapp2`:

- `testapp2/config.jsonc` — конфигурация приложения;
- `testapp2/agents` — конфигурации агентов этого окружения;
- `testapp2/skills` — текстовые навыки;
- `testapp2/todos` — сценарии TodoList;
- `testapp2/docs` — файлы контекста, доступные через `@docs/...`;
- `testapp2/.sessions`, `testapp2/.logs`, `testapp2/.store` — служебные директории.

При запуске:

- `APP_START_DIR` — корень проекта;
- `APP_WORK_DIR` — путь к `testapp2`;
- `DirPriority` конфигурируется как `[APP_START_DIR, APP_WORK_DIR]`;
- `ConfigurationApp` и producers ищут файлы сначала в `APP_START_DIR`, затем в `APP_WORK_DIR`.

Это позволяет:

- использовать общий набор skills/todos из корня;
- переопределять их в окружении, создавая файлы с теми же именами в `APP_WORK_DIR`;
- хранить изолированные логи и сессии в подкаталогах окружения.

### Контекст‑файлы (`context_files` и `@docs/...`)

Механика подключения файлов по @‑ссылкам описана в `AttachmentHelper::buildContextAttachments()` и `FileContextHelper::extractFilePathsFromBody()`:

- из текста вытаскиваются пути вида `@docs/intro.md` или `@src/classes/...`;
- для каждого пути `DirPriority::resolveFile()` ищет файл в приоритетных директориях;
- попадание файла в контекст ограничивается настройками `context_files` в `config.jsonc`:
  - `context_files.enabled` — включить/выключить механику;
  - `context_files.max_total_size` — общий лимит размера файлов;
  - `context_files.allowed_paths` / `blocked_paths` — маски путей.

Типичный пример в `config.jsonc`:

```jsonc
{
  "context_files": {
    "enabled": true,
    "max_total_size": 1048576,
    "allowed_paths": [
      "docs/*",
      "src/**/*.php"
    ],
    "blocked_paths": [
      "vendor/**",
      "node_modules/**",
      "temp/**"
    ]
  }
}
```

Подробнее о работе с файлами и вложениями см. `docs/files.md`.