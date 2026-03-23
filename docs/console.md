## Консольные команды

В проекте используются консольные команды (на базе Symfony Console) для работы с агентами, отправки сообщений и выполнения списков TodoList. Основные команды располагаются в `src/classes/command`.

### Общие правила

- Все команды используют `ConfigurationApp` и producers для поиска агентов и todolist.
- История чата и `sessionKey` позволяют продолжать диалог между запусками.
- Формат `session_id` валидируется через `ConfigurationApp::isValidSessionKey()` и должен соответствовать `YYYYMMDD-HHMMSS-μs`.

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
  - читается `RunStateDto`;
  - проверяется совпадение имени списка;
  - при наличии `history_message_count` история чата обрезается до указанного количества сообщений (через `ChatHistoryTruncateHelper`);
  - вычисляется индекс `startFromTodoIndex` для продолжения;
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
- `--log_level` (опционально) — `off|minimal|normal|debug`;
- `--quiet_logs` (флаг) — отключить логи оркестратора;
- `--date`, `--branch`, `--user` — сессионные параметры для плейсхолдеров.

Поведение:

- команда загружает три `TodoList` из `todos/`;
- передает сценарии в `TodoListOrchestrator`;
- оркестратор принудительно устанавливает `completed=0`, затем крутит `step` до `completed=1` или лимита итераций;
- в обоих финальных сценариях (успех/лимит) выполняется `finish`;
- результат печатается в JSON (`success`, `reason`, `iterations`, `restartCount`, `sessionKey`).

Пример:

- `php bin/console orchestrate --agent default --init job-init --step job-step --finish job-finish --max_iters 200`

### Команда `convert:markdown`

Класс: `ConvertToMarkdownCommand` (базовый класс: `AbstractConvertToMarkdownCommand`).

**Назначение**: преобразовать `docx/xlsx` в один markdown-файл.

Аргументы:

- `source` (обязательно) — путь к исходному `docx/xlsx` файлу;
- `target` (опционально) — путь к результирующему `.md` файлу.

Поведение:

- при запуске проверяется доступность `kreuzberg`;
- валидируется входной файл (существует, читаем, расширение `docx|xlsx`);
- markdown извлекается через `kreuzberg extract --output-format markdown --format text`;
- результат очищается через `MarkdownHelper::safeMarkdownWhitespace()`;
- если `target` не указан, рядом с исходным файлом создаётся `<имя_файла>.md`.

Пример:

- `php bin/console convert:markdown ./docs/report.docx`
- `php bin/console convert:markdown ./docs/report.xlsx ./output/report.md`

### Команда `convert:markdown-chunks`

Класс: `ConvertToMarkdownChunksCommand` (базовый класс: `AbstractConvertToMarkdownCommand`).

**Назначение**: преобразовать `docx/xlsx` в markdown и разбить результат на semantic-чанки.

Аргументы:

- `source` (обязательно) — путь к исходному `docx/xlsx` файлу;
- `directory` (опционально) — целевая директория для chunk-файлов;
- `chunk-size` (опционально, по умолчанию `4000`) — размер чанка в символах.

Поведение:

- использует тот же общий пайплайн конвертации, что и `convert:markdown`;
- для разбивки применяет `MarkdownChunckHelper::chunkBySemanticBlocks($markdown, $chunkSize)`;
- если `directory` не задан, создаётся директория рядом с исходным файлом: `<имя_файла>_chunck`;
- чанки сохраняются как `1.md`, `2.md`, `3.md` и т.д.

Пример:

- `php bin/console convert:markdown-chunks ./docs/report.docx`
- `php bin/console convert:markdown-chunks ./docs/report.xlsx ./chunks 3500`
