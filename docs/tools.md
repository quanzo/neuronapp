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
- **`IntermediateSaveTool` / `IntermediateLoadTool` / `IntermediateListTool` / `IntermediateExistTool`** — сохранение/загрузка/список/проверка промежуточных результатов в рамках `sessionKey` в `.store`.
- **`FileTreeTool`** — обзор структуры каталогов.
- **`SearchReplaceTool`** — поиск и замена по проекту с ограничениями.
- **`GitSummaryTool`** — получение краткой сводки по текущему git‑состоянию (например, изменения, ветка).
- **`WikiSearchTool` / `RuWikiSearchTool` / `UniSearchTool`** — поиск по Wikipedia/универсальным источникам (в зависимости от реализации).
- **`HttpFetchTool`** — выполнение HTTP‑запросов к внешним ресурсам (с учётом ограничений среды).
- **`OllamaSearchTool`** — взаимодействие с локальными моделями через Ollama (если настроено).

Конкретное поведение каждого инструмента реализовано в соответствующем классе, опираясь на интерфейсы NeuronAI (`ToolInterface`, `ProviderToolInterface`, `ToolkitInterface`).

### Intermediate*-инструменты: промежуточные результаты в `.store`

Набор инструментов:

- `IntermediateSaveTool` (`src/tools/IntermediateSaveTool.php`) — сохраняет промежуточный результат по метке;
- `IntermediateLoadTool` (`src/tools/IntermediateLoadTool.php`) — загружает результат по метке;
- `IntermediateListTool` (`src/tools/IntermediateListTool.php`) — возвращает список всех результатов для текущего `sessionKey`;
- `IntermediateExistTool` (`src/tools/IntermediateExistTool.php`) — проверяет наличие результата по метке.
 - `IntermediateDeleteTool` (`src/tools/IntermediateDeleteTool.php`) — удаляет сохранённый результат по метке.

Общее:

- **Подключение**: через `tools: intermediate_save`, `intermediate_load`, `intermediate_list`, `intermediate_exist`, `intermediate_delete` — создаются через `ToolRegistry::makeTool(...)`.
- **Хранилище**: директория `.store` (через `ConfigurationApp::getStoreDir()`).
- **Имена файлов**:
  - результат: `.store/intermediate_{sessionKey}_{label}.json` (небезопасные символы в частях имени заменяются на `_`);
  - индекс: `.store/intermediate_index_{sessionKey}.json` (для ускорения `list`).

Формат хранения (LLM-friendly JSON envelope) остаётся прежним:

- `schema`: `neuronapp.intermediate.v1`
- `sessionKey`: ключ сессии
- `label`: метка
- `savedAt`: ISO‑8601 дата/время сохранения
- `dataType`: `string|object|array|number|boolean|null`
- `data`: сохранённое значение (JSON‑совместимое)

Индекс хранит список элементов (без `data`) для `IntermediateListTool`.

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

Конфигурации агентов в `testapp/agents/*.php` и `testapp/agents/*.php` должны использовать именно `ShellToolFactory`, а не напрямую создавать `BashTool`/`BashCmdTool`.

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