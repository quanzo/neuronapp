## Иерархия директорий и окружения

Этот документ описывает, как проект ищет файлы конфигураций, агентов, skills и todolist через `DirPriority`, и как использовать альтернативные рабочие директории (`APP_START_DIR` / `APP_WORK_DIR`).

### Базовые понятия

- **`APP_START_DIR`** — директория, из которой запускается `bin/console` (стартовая точка).
- **`APP_WORK_DIR`** — рабочая директория приложения (например, `testapp`), задаётся переменной окружения и используется для изолированных окружений.
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

- `AgentProducer::EXTENSIONS` — `agents/<name>.php` или `agents/<name>.jsonc` (с приоритетом PHP);
- `TodoListProducer::EXTENSIONS` — `todos/<name>.txt` или `todos/<name>.md`;
- `SkillProducer::EXTENSIONS` — `skills/<name>.txt` или `skills/<name>.md`.

Source of truth для этих расширений:

- `src/classes/producers/AgentProducer.php`
- `src/classes/producers/TodoListProducer.php`
- `src/classes/producers/SkillProducer.php`

### Альтернативные окружения (`testapp` и др.)

Альтернативное рабочее окружение задаётся через `APP_WORK_DIR` и может иметь собственный набор конфигов, агентов, skills и todolist.

Пример структуры для `testapp`:

- `testapp/config.jsonc` — конфигурация приложения;
- `testapp/agents` — конфигурации агентов этого окружения;
- `testapp/skills` — текстовые навыки;
- `testapp/todos` — сценарии TodoList;
- `testapp/docs` — файлы контекста, доступные через `@docs/...`;
- `testapp/.sessions`, `testapp/.logs`, `testapp/.store` — служебные директории.

При запуске:

- `APP_START_DIR` — корень проекта;
- `APP_WORK_DIR` — путь к `testapp`;
- `DirPriority` конфигурируется как `[APP_START_DIR, APP_WORK_DIR]`;
- `ConfigurationApp` и producers ищут файлы сначала в `APP_START_DIR`, затем в `APP_WORK_DIR`.

Это позволяет:

- использовать общий набор агентов/skills/todos из более приоритетной директории;
- переопределять их в окружении, создавая файлы с теми же именами в `APP_WORK_DIR`;
- хранить изолированные логи, сессии и checkpoint-файлы в подкаталогах окружения.

#### Имена служебных файлов в директориях

Source of truth:

- `src/helpers/StorageFileHelper.php`

Канонические имена:

- `.sessions/neuron_<sessionKey>.chat`
- `.store/run_state_<sessionKey>_<agentName>.json`
- `.store/var_<sessionKey>_<name>.json`
- `.store/var_index_<sessionKey>.json`
- `.logs/<sessionKey>.log`

Инварианты:

- директории `.sessions`, `.logs`, `.store` создаются bootstrap-логикой в `bin/console.php`;
- шаблоны имён файлов не должны повторно собираться вручную в командах, сервисах или документации.

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