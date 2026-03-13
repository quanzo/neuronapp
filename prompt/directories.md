# Иерархия директорий

В `src/classes/dir` создать класс DirPriority. Конструктор класс должен принимать массив директорий. В конструкторе надо проверить существование директорий.

В классе DirPriority реализовать метод `resolveFile(string $relFileName, ?array $extensions = null): ?string` который должен определить наличие файла в одной из директорий конфигурированных в классе DirPriority. При этом возвращаем первую директорию, в которой есть файл. Если указан $extensions, то значит надо искать файл с расширением из этого списка.

В `bin/console` у нас определяется константа APP_WORK_DIR. Теперь там же будем определять константу APP_START_DIR, которая должна содержать папку в которой программа запущена.

Класс `ConfigurationApp` теперь должен использовать `DirPriority` - не используем одну директорию.

В `bin/console` теперь при создании `ConfigurationApp` туда передаем экземпляр `DirPriority` с двумя директориями: APP_START_DIR, APP_WORK_DIR.

Переписать класс `AgentProducer` на использование `DirPriority`.

На основе класса `AgentProducer` составь классы producer для элементов приложения:
- класс `TodoListProducer` используется для получения экземпляров класса `TodoList`
- класс `SkillProducer` - для получения экземпляров класса `Skill`

Все классы producer расположить в папке `src/classes/producers`.

Папки с расположением:
- для `AgentProducer` = `agents`
- для `TodoListProducer` = `todos`
- для `SkillProducer` = `skills`

Все poducer должны реализовать статисный метод `getStorageDirName(): string` который должен возвращать имя директории хранения.

Проанализировать повторяющийся функционал классов в папке проекта `src/classes/producers` и создать абстрактный класс `ProducerAbstract`, который будет содержать общие архитектурные решения для классов producer.

Класс `ProducerAbstract` переименуем в `AProducer` и перенесем в папку `src/classes`.

В `src/classes/config/ConfigurationApp.php` сделаем чтобы имя конфигурации 'config.jsonc' передавалось в виде второго параметра в методе `init` и соответственно, чтобы имя файла конфигурации было свойством класса ConfigurationApp.

В папке `src/classes/producers` у нас классы, основная задача которых возвращать элементы приложения. `ConfigurationApp` настривается на определенные папки приложения и хранит опции приложения. Поэтому в `ConfigurationApp` добавить методы, которые позволяют возвращать элементы элементы. Следует предусмотреть, чтобы классы-prucer не создавались повторно при множественном вызове.

## Альтернативные рабочие директории (APP_WORK_DIR)

В качестве рабочего каталога приложения может использоваться не только корень проекта, но и альтернативные директории
для изолированных окружений (например, `testapp2`).

### Общая схема

- `APP_START_DIR` — директория, из которой запускается `bin/console`.
- `APP_WORK_DIR` — рабочий каталог приложения (например, `testapp` или `testapp2`).
- `DirPriority` настраивается с двумя директориями: `[APP_START_DIR, APP_WORK_DIR]`.
- Все элементы (`agents`, `skills`, `todos`, `.sessions`, `.logs`, `.store`) ищутся через `DirPriority`.

### Пример для `testapp2`

Структура:

- `testapp2/config.jsonc` — конфиг приложения для этого окружения.
- `testapp2/agents` — конфигурации агентов.
- `testapp2/skills` — текстовые навыки.
- `testapp2/todos` — сценарии TodoList.
- `testapp2/docs` — файлы контекста, подключаемые через @docs/… в skills/todos.
- `testapp2/.sessions`, `testapp2/.logs`, `testapp2/.store` — служебные директории.

При запуске:

- переменная окружения `APP_WORK_DIR` указывает на `testapp2`;
- `DirPriority` конфигурируется на `[APP_START_DIR, APP_WORK_DIR]`;
- `ConfigurationApp` через producers читает `agents/`, `skills/`, `todos/` сначала из `APP_WORK_DIR`, при отсутствии — из `APP_START_DIR`.

### Настройка context_files

В `config.jsonc` можно управлять автоподключением файлов по @-ссылкам:

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

- `context_files.enabled` — включить/выключить механику.
- `context_files.max_total_size` — общий лимит размера подключённых файлов (в байтах).
- `context_files.allowed_paths` — список glob-масок относительных путей, из которых разрешено подгружать контекст.
- `context_files.blocked_paths` — дополнительные маски для исключения нежелательных файлов.

`AttachmentHelper::buildContextAttachments()` учитывает эти опции, фильтруя пути, извлечённые из текста через `FileContextHelper::extractFilePathsFromBody()`.