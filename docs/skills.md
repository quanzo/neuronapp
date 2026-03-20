## Навыки (`Skill`)

Этот документ описывает текстовые навыки (`Skill`), их формат, опции в YAML‑шапке и связь с кодом (`app\modules\neuron\classes\skill\Skill` и `AbstractPromptWithParams`).

### Формат файла skill

Файл навыка хранится в директории `skills/` (через `DirPriority`) и состоит из:

- блока опций в формате YAML/JSON‑подобного объекта, ограниченного линиями из `-`;
- текстового тела навыка.

Пример:

```text
-----
description: "Поиск информации в Википедии"
params: {
  "query": {
    "type": "string",
    "description": "Запрос к поиску",
    "required": true
  }
}
tools: wiki_search
agent: default
pure_context: 0
-----
Найди в русской Википедии подробную статью по теме: "$query".
Сделай краткое структурированное резюме.
```

Парсинг этого формата реализован в `APromptComponent`/`AbstractPromptWithParams` и используется как для `Skill`, так и для `TodoList`.

### Плейсхолдеры и параметры (`params`)

В теле skill можно использовать плейсхолдеры вида `$paramName`. Имя параметра:

- только латинские буквы, без пробелов;
- чувствительно к регистру;
- соответствует регулярному выражению `[a-zA-Z]+`.

Список разрешённых параметров задаётся в опции `params` (JSON‑объект). Каждый параметр описывается структурой, которая парсится в `ParamDto` через `ParamListDto::tryFromOptionValue()`:

```yaml
params: {
  "topic": {
    "type": "string",
    "description": "Тема исследования",
    "required": true,
    "default": "PHP"
  },
  "date": {
    "type": "string",
    "description": "Дата запуска",
    "required": false
  }
}
```

Подстановка значений выполняется в `Skill::getSkill()` с помощью `PlaceholderHelper::renderWithParams()`. Итоговый набор параметров формируется методом `AbstractPromptWithParams::buildEffectiveParams()` с приоритетом:

1. **runtime‑параметры** (аргумент `$params` при вызове `getSkill()` / `execute()`);
2. **сессионные параметры** (для `Skill` сейчас не используются, но поддерживаются на уровне базового класса);
3. **значения `default`**, заданные в `params`.

Необъявленные в `params` плейсхолдеры считаются ошибкой конфигурации и попадают в `checkErrors()` через `validateParams()` / `PlaceholderHelper::validateParamList()`.

### Опция `tools` — встроенные инструменты

Skill может явно объявлять зависимость от встроенных инструментов (например, `wiki_search`, `git_summary`) через строковую опцию `tools`:

```yaml
tools: wiki_search, git_summary
```

- Опция парсится в `AbstractPromptWithParams::parseTools()` и доступна через `getNeedTools()`.
- Валидация типа выполняется в `validateToolsOption()`: опция обязана быть строкой, иначе в списке ошибок появится запись с типом `invalid_tools_option_type`.
- При выполнении навыка инструменты подключаются к сессионной конфигурации агента в трейте `AttachesSkillToolsTrait` с использованием `ToolRegistry::makeTool()`.

Список доступных имён инструментов и их реализация описаны в `ToolRegistry` и `docs/tools.md`.

### Опция `skills` — зависимые навыки

Через опцию `skills` можно подключить другие навыки как инструменты LLM:

```yaml
skills: helper/prepare_context, helper/summarize
```

- Строка парсится методом `parseSkills()` базового класса.
- Список имён доступен через `HasNeedSkillsTrait::getNeedSkills()`.
- Валидация выполняется в `validateSkillsOption()` и запрещает самоссылки (тип ошибки `self_referenced_skill`).
- Подключение инструментов от зависимых skills происходит в `AttachesSkillToolsTrait::attachSkillToolsToSession()`.

### Опции `agent` и `pure_context`

- **`agent`** — имя агента‑исполнителя (см. `docs/config.md` и `docs/agents.md`). Если не указан, используется агент, переданный извне, либо установленный как `default` в приложении.
- **`pure_context`** — управляет использованием истории чата:
  - по умолчанию (`Skill::getDefaultPureContext()` возвращает `false`) навык выполняется **в общем контексте агента** (копия истории не создаётся);
  - при `pure_context: 1` или `true` используется клон сессии через `ChatHistoryCloneMode::RESET_EMPTY` / `COPY_CONTEXT` (см. реализацию в `AbstractPromptWithParams::isPureContext()` и `Skill::execute()`).

### Выполнение навыка (`execute()`)

Основной метод исполнения — `Skill::execute(MessageRole $role, array $attachments = [], ?array $params = null): Future`:

- получает конфигурацию агента через `getConfigurationAgent()` (`ConfigurationApp::getAgent()` учитывает `DirPriority`);
- формирует текст навыка через `getSkill($params ?? [])`;
- дополняет вложения файлами контекста по `@docs/...` и другим путям, разрешённым `AttachmentHelper::buildContextAttachments()` и настройками `context_files.*` в `config.jsonc`;
- при необходимости создаёт клон сессии (чистый контекст) и подключает инструменты из `skills`/`tools`;
- отправляет сообщение в LLM и возвращает `Future` с результатом.

Дополнительно, каждый запуск логируется через `RunLogger`:

- `startRun('skill', $this->getName(), $context)` при старте;
- `finishRun($runId, ['steps' => 1])` при успешном завершении;
- `finishRun($runId, ['steps' => 0], $e)` при исключении.

Структура этих логов описана в `docs/logs.md`.

### Преобразование skill в инструмент LLM (`getTool()`)

Метод `Skill::getTool(MessageRole $role = MessageRole::USER): Tool` строит объект `NeuronAI\Tools\Tool`:

- имя инструмента — производное от имени файла навыка (поддиректории заменяются на `__`);
- описание — опция `description` (если задана);
- параметры — по `ParamListDto` (тип, описание, признак `required`, без значения `default`).

Перед созданием инструмента вызывается `checkErrors()`. Критические ошибки (например, неверный тип `params`) приводят к выбросу `RuntimeException` и запрещают использование такого skill как инструмента.

### Проверка конфигурации (`checkErrors()`)

Для отладки и тестов у `Skill` есть общий метод проверки:

- `AbstractPromptWithParams::checkErrors()` агрегирует ошибки:
  - пустое тело (если проверяется наследником),
  - ошибки `params` (неверный JSON/тип, неописанные плейсхолдеры),
  - ошибки `skills` (`invalid_skills_option_type`, `self_referenced_skill`),
  - ошибки `tools` (`invalid_tools_option_type`).

Рекомендуется вызывать `checkErrors()` в тестах для фиксации ожидаемого поведения и корректной валидации YAML‑шапок навыков.

### Где посмотреть примеры

- Базовые примеры навыков находятся в `samples/skills`.
- Примеры для рабочего/тестового окружения находятся в `testapp/skills`.