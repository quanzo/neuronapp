## Встроенные инструменты (`src/tools`)

Этот документ описывает основные инструменты, доступные LLM через конфигурацию агента (`ConfigurationAgent::getTools()`), и то, как они подключаются к skills и todolist.

### Общая модель

- Базовый абстрактный класс для инструментов модуля — `app\modules\neuron\tools\ATool`.
- Конкретные инструменты реализованы в `src/tools/*Tool.php` (например, `GlobTool`, `GrepTool`, `ViewTool`, `BashTool`, `BashCmdTool`, `GitSummaryTool`, `WikiSearchTool`, `RuWikiSearchTool`, `UniSearchTool`, `FileTreeTool`, `SearchReplaceTool`, `HttpFetchTool`, `OllamaSearchTool`).
- Инструменты передаются в `ConfigurationAgent::$tools` и используются агентом NeuronAI при вызове `getTools()`.
- Навыки и todolist могут подключать встроенные инструменты через:
  - опцию `tools` (строка с именами инструментов);
  - `ToolRegistry::makeTool()` — фабрика, создающая экземпляры по имени (`wiki_search`, `ru_wiki_search`, `uni_search`, `git_summary`).

При выполнении:

- `Skill::execute()` и `TodoList::execute()` создают сессионную копию конфигурации агента (при необходимости);
- через `AttachesSkillToolsTrait::attachSkillToolsToSession()` в `ConfigurationAgent::$tools` добавляются:
  - инструменты, построенные из зависимых skills (`Skill::getTool()`);
  - встроенные инструменты, созданные `ToolRegistry`.

### Обзор ключевых инструментов

- **`GlobTool`** — поиск файлов по шаблонам (glob‑паттерны).
- **`GrepTool`** — поиск текста внутри файлов.
- **`ViewTool`** — чтение содержимого файлов.
- **`EditTool`** — безопасное редактирование файлов проекта (используется только по запросу пользователя).
- **`FileTreeTool`** — обзор структуры каталогов.
- **`SearchReplaceTool`** — поиск и замена по проекту с ограничениями.
- **`GitSummaryTool`** — получение краткой сводки по текущему git‑состоянию (например, изменения, ветка).
- **`WikiSearchTool` / `RuWikiSearchTool` / `UniSearchTool`** — поиск по Wikipedia/универсальным источникам (в зависимости от реализации).
- **`HttpFetchTool`** — выполнение HTTP‑запросов к внешним ресурсам (с учётом ограничений среды).
- **`OllamaSearchTool`** — взаимодействие с локальными моделями через Ollama (если настроено).

Конкретное поведение каждого инструмента реализовано в соответствующем классе, опираясь на интерфейсы NeuronAI (`ToolInterface`, `ProviderToolInterface`, `ToolkitInterface`).

### Bash‑инструменты и безопасность

Для работы с оболочкой предусмотрены два уровня:

- `BashTool` — общий инструмент для выполнения shell‑команд;
- `BashCmdTool` — более специализированный инструмент для запуска отдельных команд.

Чтобы ограничить их возможности, используется фабрика `ShellToolFactory` (`src/helpers/ShellToolFactory.php`), которая создаёт преднастроенные профили:

- **readonly** — только безопасные команды чтения (например, `git status`, `git diff`, `ls`, `php -v`, `composer show`);
- **diagnostics** — команды диагностики окружения (`php -m`, `composer validate`, и т.п.), без модифицирующих операций.

Эти профили задают:

- `allowedPatterns` — белые списки регулярных выражений для допустимых команд;
- `blockedPatterns` — явные запреты (`rm`, `sudo`, деструктивные git‑операции и т.д.);
- таймауты, лимиты на объём вывода и другие параметры безопасности.

Конфигурации агентов в `testapp/agents/*.php` и `testapp2/agents/*.php` должны использовать именно `ShellToolFactory`, а не напрямую создавать `BashTool`/`BashCmdTool`.

### Инструменты как зависимость skills и todolist

При настройке YAML‑шапок:

- для skills:
  - опция `tools` объявляет имена встроенных инструментов, которые будут доступны при выполнении навыка;
  - опция `skills` объявляет зависимые skills, чьи инструменты также подключаются;
- для todolist:
  - `skills` — список навыков, доступных как инструменты в рамках выполнения списка;
  - `tools` — дополнительные встроенные инструменты.

Механизм:

- `AbstractPromptWithParams::getNeedTools()` возвращает список имён инструментов;
- `HasNeedSkillsTrait` и `AttachesSkillToolsTrait` собирают:
  - инструменты зависимых skills (`Skill::getTool()`, строящий `NeuronAI\Tools\Tool`);
  - встроенные инструменты через `ToolRegistry::makeTool($name, $agentCfg)`;
- затем расширенный список передаётся в сессионную конфигурацию агента, не изменяя базовый конфиг (`cloneForSession`).

Подробности о skills и todolist см. в `docs/skills.md` и `docs/todolist.md`.