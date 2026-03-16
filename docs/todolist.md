## Списки задач (`TodoList`)

Этот документ описывает формат файлов TodoList, работу класса `TodoList` и команду `todolist`.

### Формат файла TodoList

Файлы хранятся в директории `todos/` (через `DirPriority`) и содержат:

- необязательный блок опций (YAML/JSON‑подобный объект), ограниченный линиями из `-`;
- список задач, каждая из которых может быть многострочной.

Пример:

```text
-----
description: "Код‑ревью изменений"
params: {
  "branch": {
    "type": "string",
    "description": "Имя ветки",
    "required": true
  },
  "date": {
    "type": "string",
    "description": "Дата запуска",
    "required": false
  }
}
skills: review/summary, review/checklist
tools: git_summary
pure_context: 1
-----
1. Проведи обзор изменений в ветке "$branch".

2. Составь список рисков и рекомендаций.
```

Разбор выполняется в `TodoList::__construct()` на базе `APromptComponent`:

- опции парсятся в массив `options` (`getOptions()`);
- тело превращается в список объектов `Todo` (очередь FIFO).

### Опции TodoList

Основные опции в шапке:

- **`description`** — краткое описание списка;
- **`params`** — схема параметров для подстановки в тело задач (см. ниже);
- **`skills`** — имена зависимых skills, подключаемых как инструменты;
- **`tools`** — имена встроенных инструментов;
- **`agent`** — имя агента‑исполнителя (обычно не задаётся; агент выбирается командой);
- **`pure_context`** — использовать ли чистый контекст истории чата (по умолчанию `true` для TodoList).

Проверка опций и тела выполняется в `AbstractPromptWithParams::checkErrors()`:

- корректность `params` и соответствие плейсхолдерам;
- формат `skills` и отсутствие самоссылок;
- тип `tools` (строка).

### Параметры и плейсхолдеры

В теле задач можно использовать плейсхолдеры `$paramName` (только латинские буквы, `[a-zA-Z]+`, с учётом регистра). Для них в опции `params` задаётся описание через `ParamListDto`/`ParamDto`:

```yaml
params: {
  "topic": {
    "type": "string",
    "description": "Тема исследования",
    "required": true,
    "default": "PHP"
  }
}
```

Итоговый набор значений формируется методом `AbstractPromptWithParams::buildEffectiveParams()` и используется в `TodoList::execute()` при вызове `Todo::getTodo($effectiveParams)`. Приоритет значений:

1. **runtime‑параметры** (аргумент `$params` при вызове `execute()`);
2. **сессионные параметры** (массив из `SessionParamsDto::toArray()` — например, `date`, `branch`, `user`);
3. **`default`** из описания параметров.

Сессионные плейсхолдеры `$date`, `$branch`, `$user` считаются обычными параметрами с именами `date`, `branch`, `user` и должны быть объявлены в `params`. Значения передаются из CLI‑опций `--date`, `--branch`, `--user` команды `todolist`.

### Выполнение списка (`TodoList::execute()`)

Сигнатура основного метода:

```php
public function execute(
    MessageRole $role = MessageRole::USER,
    array $attachments = [],
    ?array $params = null,
    int $startFromTodoIndex = 0,
    ?SessionParamsDto $sessionParams = null
): Future;
```

Последовательность:

- выбирается конфигурация агента (`getConfigurationAgent()`), при `pure_context` создаётся либо клон сессии (`cloneForSession(ChatHistoryCloneMode::RESET_EMPTY)`), либо используется общий агент;
- к сессионной конфигурации подключаются инструменты из `skills` и `tools` через `AttachesSkillToolsTrait::attachSkillToolsToSession()` и `ToolRegistry`;
- если включена история чата, создаётся/обновляется `RunStateDto` в директории `.store` через `ConfigurationApp::getStoreDir()` и `RunStateCheckpointHelper`;
- формируется общий набор параметров (`effectiveParams`) и последовательно выполняются todos:
  - текст задачи = `Todo::getTodo($effectiveParams)`;
  - из текста извлекаются @‑ссылки на файлы (`AttachmentHelper::buildContextAttachments()`), учитывая настройки `context_files.*` из `config.jsonc`;
  - сообщение отправляется в LLM через `ConfigurationAgent::sendMessageWithAttachments()`;
  - по завершении задачи обновляется чекпоинт (`last_completed_todo_index`, `history_message_count`);
- после успешного выполнения всех задач чекпоинт удаляется, а в качестве результата возвращается клон истории чата (`ChatHistoryInterface`).

Каждый запуск логируется через `RunLogger` (тип `todolist`, имя списка, количество выполненных шагов).

### Resume/abort и чекпоинты

Механика возобновления и прерывания выполнения реализована совместно:

- `ConfigurationAgent::getBlankRunStateDto()` / `getExistRunStateDto()` и `RunStateDto` описывают состояние запущенного списка;
- `RunStateCheckpointHelper` читает/записывает файлы чекпоинтов в `.store`;
- `ChatHistoryTruncateHelper` позволяет обрезать историю сообщений до указанного количества при resume.

Команда `todolist`:

- при `--resume` восстанавливает `startFromTodoIndex` и, при наличии, обрезает историю чата до `history_message_count`;
- при `--abort` удаляет чекпоинт, не выполняя список;
- при обычном запуске с `--session_id` проверяет наличие незавершённого run и просит явно указать `--resume` или `--abort`.

Подробнее о CLI‑поведении см. `docs/console.md`.

### Где посмотреть примеры

- Файл `prompt/todolist.md` описывает исходный формат и идеологию TodoList.
- Реальные сценарии находятся в `testapp/todos` и `testapp2/todos` и демонстрируют:
  - использование `params` и значений `default`;
  - сессионные параметры `$date`, `$branch`, `$user`;
  - подключение `@docs/...` файлов как контекста.