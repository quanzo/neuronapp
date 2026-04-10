## Консольные команды

В проекте используются консольные команды (на базе Symfony Console) для работы с агентами, отправки сообщений и выполнения списков TodoList. Основные команды располагаются в `src/classes/command`.

### Общие правила

- Точка входа: `bin/console.php` использует `TimedConsoleApplication` — после выполнения команды в stderr выводится строка вида `Время выполнения: X.XXX с` (в режиме `--quiet` / `-q` не показывается).
- Все команды используют `ConfigurationApp` и producers для поиска агентов и todolist.
- История чата и `sessionKey` позволяют продолжать диалог между запусками.
- Формат `session_id` валидируется через `ConfigurationApp::isValidSessionKey()` и должен соответствовать `Ymd-His-u-userId`.

#### Формат `session_id`

Source of truth:

- `src/helpers/SessionKeyHelper.php`
- `ConfigurationApp::describeSessionKeyFormat()`

Канонический формат:

- `Ymd-His-u-userId`
- пример: `20250301-143022-123456-0`

Ошибки и edge cases:

- значение без `userId` считается невалидным для CLI;
- при неверном формате команды `simplemessage` и `todolist` должны показывать сообщение, построенное из `ConfigurationApp::describeSessionKeyFormat()`, а не из вручную захардкоженной строки.

### Команды очистки сессий

Команды для удаления файлов, связанных с сессиями (история, чекпоинты `.store`, результаты `.store`, логи `.logs`).

#### Команда `session:clear`

Класс: `ClearSessionCommand`.

**Назначение**: очистить одну сессию по `--session_id`.

Опции:

- `--session_id` (обязательно) — ключ сессии;
- `--dry-run` — показать список файлов-кандидатов без удаления;
- `-y|--yes` — не спрашивать подтверждение (нужно для non-interactive).

Примеры:

- `php bin/console session:clear --session_id 20250301-143022-123456-0 --dry-run`
- `php bin/console session:clear --session_id 20250301-143022-123456-0 --yes`

#### Команда `sessions:clear`

Класс: `ClearAllSessionsCommand`.

**Назначение**: очистить все сессии.

Ключи сессий собираются как union по:

- `.sessions` (файлы `neuron_*.chat`);
- `.store` (файлы `run_state_*.json` и `var_index_*.json`);
- `.logs` (файлы `*.log`).

Опции:

- `--dry-run` — показать список sessionKey и кандидатов без удаления;
- `-y|--yes` — удалить без подтверждения (нужно для non-interactive).

Примеры:

- `php bin/console sessions:clear --dry-run`
- `php bin/console sessions:clear --yes`

### Команда `simplemessage`

Класс: `SimpleMessageCommand`.

**Назначение**: отправить одно сообщение выбранному агенту и получить ответ, используя `TodoList` с одной задачей.

Поддерживаемые опции:

- `--agent` (обязательно) — имя агента (`agents/<name>.php|jsonc`);
- `--message` (обязательно) — текст пользовательского сообщения;
- `--session_id` (опционально) — ключ сессии для продолжения диалога:
  - формат проверяется через `ConfigurationApp::isValidSessionKey()`;
  - существование файла истории проверяется через `ConfigurationApp::sessionExists()` без привязки к агенту;
- `--format` (опционально, по умолчанию `md`) — формат вывода (`md`, `txt`, `json`);
- `-f|--file` (массив путей) — файлы, которые будут добавлены как вложения через `AttachmentHelper::buildAttachmentsFromPaths()`.

Поведение:

- при `--session_id` конфиг приложения получает этот ключ через `ConfigurationApp::setSessionKey()`;
- создаётся `TodoList` с одним пунктом (сообщением);
- запускается `TodoList::execute()` с ролью `MessageRole::USER`;
- последний ответ ассистента форматируется `ConsoleHelper::formatOut()` и выводится вместе с sessionKey.

### Команда `todolist`

Класс: `TodolistCommand`.

**Назначение**: выполнить файл списка заданий из `todos/` через выбранного агента.

Поддерживаемые опции:

- `--todolist` (обязательно, кроме режима `--abort`) — имя списка (`todos/<name>.md|txt`);
- `--agent` (обязательно) — имя агента;
- `--session_id` (опционально) — ключ сессии для продолжения/учёта истории;
- `--resume` (флаг) — продолжить незавершённый run с последнего чекпоинта;
- `--abort` (флаг) — сбросить состояние незавершённого run (чекпоинт) без выполнения списка;
- `--format` (опционально, по умолчанию `md`) — формат вывода (`md`, `txt`, `json`);
- `-f|--file` (массив путей) — дополнительные файлы‑вложения;
- `--date`, `--branch`, `--user` — сессионные параметры, используемые как значения плейсхолдеров `$date`, `$branch`, `$user` (при условии, что они объявлены в `params` списка).

Поведение:

- при `--abort`:
  - ищется `RunStateDto` через `ConfigurationAgent::getExistRunStateDto()`;
  - если он есть, удаляется файл чекпоинта и команда завершается успешно;
- при `--resume`:
  - строится единый `TodoListResumePlanDto` через `TodoListResumeHelper::buildPlan()`;
  - проверяется совпадение имени списка и `sessionKey`;
  - при наличии `history_message_count` helper применяет rollback истории;
  - если `history_message_count` отсутствует, команда продолжает выполнение с `last_completed_todo_index + 1`, но пишет warning в лог;
  - вычисленный `startFromTodoIndex` берётся из `TodoListResumePlanDto`, а не считается локально в команде;
- при обычном запуске с `--session_id` и незавершённым run:
  - команда просит явно указать `--resume` или `--abort` и завершается с ошибкой.

Выполнение:

- `TodoList` получает конфигурацию агента (`setDefaultConfigurationAgent($agentCfg)`);
- из `--date/--branch/--user` формируется `SessionParamsDto`, который передаётся в `TodoList::execute()` и влияет на значения параметров;
- вложения из `-f|--file` превращаются в DTO через `AttachmentHelper::buildAttachmentsFromPaths()` и используются для всех задач;
- выводится ответ последнего сообщения из истории чата, отформатированный `ConsoleHelper::formatOut()`.

### Другие команды

В проекте также могут присутствовать дополнительные команды (`WikiCommand`, `RuwikiCommand`, `InteractiveCommand`, `HelloCommand` и др.), которые используют те же базовые механизмы:

- `ConfigurationApp` и producers для поиска агентов и сценариев;
- `ConfigurationAgent` для работы с LLM и историей чата;
- `AttachmentHelper` и `ConsoleHelper` для работы с вложениями и форматированием вывода.

Подробности по каждому такому классу можно посмотреть в `src/classes/command`.

#### Команда `interactive`

Класс: `InteractiveCommand`.

**Назначение**: запустить интерактивный TUI (область вывода + многострочный ввод + статус-бар).

Внутренняя архитектура и точки расширения описаны в `docs/tui.md` (ввод → события → reducer → renderer, а также безопасное восстановление режимов терминала).

### Команда `orchestrate`

Класс: `OrchestrateCommand`.

**Назначение**: запуск внешнего оркестратора циклов `init -> step -> finish` поверх `TodoList`.

Поддерживаемые опции:

- `--agent` (обязательно) — имя агента;
- `--init` (обязательно) — имя init-todolist;
- `--step` (обязательно) — имя step-todolist;
- `--finish` (обязательно) — имя finish-todolist;
- `--session_id` (опционально) — ключ существующей сессии;
- `--max_iters` (опционально, default: `100`) — максимум итераций step;
- `--restart_on_fail` (флаг) — разрешить перезапуск цикла при ошибках;
- `--max_restarts` (опционально, default: `0`) — максимум перезапусков;
- `--date`, `--branch`, `--user` — сессионные параметры для плейсхолдеров.

Поведение:

- команда загружает три `TodoList` из `todos/`;
- передает сценарии в `TodoListOrchestrator`;
- оркестратор принудительно устанавливает `completed=0`, затем крутит `step` до `completed=1` или лимита итераций;
- перед каждым запуском списка (`init`, каждая итерация `step`, `finish`) при наличии checkpoint строится такой же `TodoListResumePlanDto`, как у `todolist --resume`;
- если rollback истории невозможен из-за отсутствия `history_message_count`, оркестратор публикует событие `orchestrator.resume_history_missing`, но продолжает выполнение с рассчитанного индекса;
- в обоих финальных сценариях (успех/лимит) выполняется `finish`;
- жизненный цикл оркестратора публикуется в `EventBus` событиями `orchestrator.*`;
- результат печатается в JSON (`success`, `reason`, `iterations`, `restartCount`, `sessionKey`).

Пример:

- `php bin/console orchestrate --agent default --init job-init --step job-step --finish job-finish --max_iters 200`
