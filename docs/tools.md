## Встроенные инструменты (`src/tools`)

Этот документ описывает основные инструменты, доступные LLM через конфигурацию агента (`ConfigurationAgent::getTools()`), и то, как они подключаются к skills и todolist.

### Общая модель

- Базовый абстрактный класс для инструментов модуля — `app\modules\neuron\tools\ATool`.
- Конкретные инструменты реализованы в `src/tools/*Tool.php` (например, `GlobTool`, `GrepTool`, `ViewTool`, `BashTool`, `BashCmdTool`, `GitSummaryTool`, `WikiSearchTool`, `RuWikiSearchTool`, `UniSearchTool`, `FileTreeTool`, `SearchReplaceTool`, `WebFetchTool`, `OllamaSearchTool`).
- Инструменты передаются в `ConfigurationAgent::$tools` и используются агентом NeuronAI при вызове `getTools()`.
- Навыки и todolist могут подключать встроенные инструменты через:
  - опцию `tools` (строка с именами инструментов);
  - `ToolRegistry::makeTool()` — фабрика, создающая экземпляры по имени (`wiki_search`, `ru_wiki_search`, `uni_search`, `git_summary`, `todo_goto`).
  - `ToolRegistry::register()` / `ToolRegistry::registerAlias()` — расширение реестра инструментов на этапе bootstrap приложения (например, для модулей/плагинов).

#### Регистрация собственного tool

Фабрика инструмента должна иметь сигнатуру:

- `fn(string $name, ConfigurationAgent $cfg): ToolInterface`

где:

- `$name` — короткое имя, под которым tool запрошен (`makeTool($name, ...)`). Его удобно использовать, если один и тот же класс инструмента должен “светиться” под разными именами (алиасы) или если вы хотите прокинуть имя в метрики/логи.
- `$cfg` — конфигурация текущей сессии агента.

Пример регистрации (в bootstrap приложения/модуля):

```php
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\helpers\ToolRegistry;
use NeuronAI\Tools\ToolInterface;

ToolRegistry::register(
    'my_tool',
    static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new MyTool()
);
```

Пример алиаса:

```php
ToolRegistry::registerAlias('my_tool_v2', 'my_tool');
```

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
- **`chat_history.*`** — просмотр полной истории сообщений (размер, метаданные, получение сообщения по индексу).
- **`VarSetTool` / `VarGetTool` / `VarListTool` / `VarExistTool`** — установка/получение/список/проверка результатов в рамках `sessionKey` в `.store`.
- **`TodoGotoTool`** (`todo_goto`) — запрос перехода к пункту TodoList по номеру (`point`, 1-based), переход применяется циклом `TodoList::execute()`.
- **`TodoCompletedTool`** (`todo_completed`) — установить флаг `completed` в `.store` для внешнего оркестратора (`done/not_done`, `1/0`, `исполнено/не исполнено`).
- **`FileTreeTool`** — обзор структуры каталогов.
- **`SearchReplaceTool`** — поиск и замена по проекту с ограничениями.
- **`ChunckGrepTool`** — поиск строки в файле с возвратом семантических чанков вокруг совпадений.
- **`GitSummaryTool`** — получение краткой сводки по текущему git‑состоянию (например, изменения, ветка).
- **`WikiSearchTool` / `RuWikiSearchTool` / `UniSearchTool`** — поиск по Wikipedia/универсальным источникам (в зависимости от реализации).
- **`WebFetchTool`** — единый инструмент для HTTP‑запросов (raw) и извлечения контента (extract/prompt); см. ниже.
- **`OllamaSearchTool`** — взаимодействие с локальными моделями через Ollama (если настроено).

Конкретное поведение каждого инструмента реализовано в соответствующем классе, опираясь на интерфейсы NeuronAI (`ToolInterface`, `ProviderToolInterface`, `ToolkitInterface`).

### `web_fetch` / `http_fetch`: единый WebFetchTool с режимами

В проекте используется **один** класс инструмента: `app\modules\neuron\tools\WebFetchTool` (`src/tools/WebFetchTool.php`).

Доступные имена (алиасы в `ToolRegistry`):

- `web_fetch` — основной алиас;
- `http_fetch` — алиас для обратной совместимости со старой конфигурацией (зарегистрирован отдельной фабрикой, чтобы сохранить публичное имя инструмента).

#### Заголовки (User-Agent = Firefox)

- По умолчанию исходящие запросы отправляются с идентичностью **Firefox**:
  `app\modules\neuron\classes\dto\tools\HttpFetchRequestHeadersDto::firefoxDefaults()`.
- Дополнительные заголовки передаются через DTO в конструктор `WebFetchTool` или методом `setDefaultRequestHeaders()`.
  Переданный набор **сливается** с дефолтами Firefox; совпадающие по имени (без учёта регистра) заголовки **перекрываются** вашими значениями.

#### Режимы (параметр `mode`)

- `mode=raw` — “сырой” HTTP: возвращает **JSON** со статусом/заголовками/телом.
  Доп. параметры: `method=GET|HEAD`, `follow_redirects`, `max_redirects`.
- `mode=extract` — извлекает текст: HTML → markdown-like, возвращает **plain-text**.
- `mode=prompt` — как `extract`, но затем применяет `prompt` через LLM и возвращает **plain-text**.

### `WebFetchTool` (`web_fetch`)

- Класс: `app\modules\neuron\tools\WebFetchTool` (`src/tools/WebFetchTool.php`).
- Подключение: через `tools: web_fetch` (создаётся через `ToolRegistry::makeTool('web_fetch', ...)`).
- Формат ответа зависит от `mode`:
  - `raw` → JSON
  - `extract|prompt` → plain-text

Назначение:

- получить читаемый текст со страницы (HTML конвертируется в markdown‑like текст);
- при наличии `prompt` — применить его к контенту через LLM (используется текущая модель агента, но в «чистой» сессии без истории и без tools).

Параметры:

- `mode` (string, optional) — `raw|extract|prompt` (по умолчанию `extract`).
- `url` (string, required) — абсолютный `http/https` URL. HTTP автоматически повышается до HTTPS.
- `method` (string, optional) — только для `raw`: `GET|HEAD` (по умолчанию `GET`).
- `prompt` (string, optional) — инструкция/вопрос к контенту. Если не задан — возвращается извлечённый текст.
- `timeout_ms` (int, optional) — таймаут в мс.
- `max_body_size` (int, optional) — максимальный размер тела ответа в байтах.
- `cache_ttl_seconds` (int, optional) — TTL кэша (для `extract|prompt`); `0` отключает кэш.
- `follow_redirects` (bool, optional) — только для `raw`: следовать редиректам автоматически (по умолчанию `true`).
- `max_redirects` (int, optional) — только для `raw`: максимум редиректов (по умолчанию `5`).
- Исходящие заголовки:
  - по умолчанию инструмент использует **набор заголовков в стиле Firefox** (включая `User-Agent`);
  - при создании инструмента можно передать/установить дополнительные заголовки, которые сольются с дефолтами Firefox с перекрытием по имени.

Ограничения и безопасность:

- **Редиректы на другой host** инструмент не следует автоматически. Вместо этого вернёт сообщение с `Redirect URL`, которое нужно передать в повторный вызов.
- **Бинарный контент** (например, `application/pdf`) не поддерживается — вернётся `Error: ...`.
- Аутентифицированные/private URL обычно недоступны; для них предпочтительнее специализированные MCP-инструменты с авторизацией.

### Инструменты просмотра истории (`chat_history.*`)

Назначение: дать LLM доступ к **полной истории** сообщений сессии (0-based индексация), чтобы она могла осознанно ссылаться на прошлые сообщения и подгружать нужные фрагменты.

Набор:

- `chat_history.size` — размер полной истории (количество сообщений).
- `chat_history.meta` — метаданные сообщения по индексу без текста (роль, длина, tool-сигнатура).
- `chat_history.message` — получить сообщение по индексу (роль + текст + tool-сигнатура).
- `chat_history.grep` — поиск строки/regex в полной истории (индекс сообщения, роль, номер строки, фрагмент).
- `chunk_grep` — поиск по файлу с возвратом семантических markdown-чанков вокруг совпадений.

Формат ответов — LLM-friendly JSON, ошибки возвращаются как JSON с полями `error`, `count`, `minIndex`, `maxIndex`.

Примеры (псевдо-вызовы):

```json
{"tool":"chat_history.size","args":{}}
```

```json
{"tool":"chat_history.meta","args":{"index":0}}
```

```json
{"tool":"chat_history.message","args":{"index":0}}
```

```json
{"tool":"chat_history.grep","args":{"pattern":"ошибка","caseInsensitive":true,"maxMatches":10}}
```

### Var*-инструменты: результаты в `.store`

Набор инструментов:

- `VarSetTool` (`src/tools/VarSetTool.php`) — устанавливает (сохраняет) результат по метке;
- `VarGetTool` (`src/tools/VarGetTool.php`) — получает результат по метке;
- `VarListTool` (`src/tools/VarListTool.php`) — возвращает список всех результатов для текущего `sessionKey`;
- `VarExistTool` (`src/tools/VarExistTool.php`) — проверяет наличие результата по метке.
 - `VarUnsetTool` (`src/tools/VarUnsetTool.php`) — удаляет сохранённый результат по метке.
- `VarPadTool` (`src/tools/VarPadTool.php`) — дополняет (append) строковые данные по метке, сохраняя переводы строк.

Общее:

- **Подключение**: через `tools: var_set`, `var_get`, `var_list`, `var_exist`, `var_unset`, `var_pad` — создаются через `ToolRegistry::makeTool(...)`.
- **Хранилище**: директория `.store` (через `ConfigurationApp::getStoreDir()`).
- **Формат ответов**: инструменты возвращают LLM-friendly JSON через `VarToolResultDto`; поля со значением `null` **не включаются** в результат.
- **Имена файлов**:
  - результат: `.store/var_{sessionKey}_{label}.json`;
  - индекс: `.store/var_index_{sessionKey}.json`.

Source of truth для имён файлов:

- `src/helpers/StorageFileHelper.php`
- `src/classes/storage/VarStorage.php`

Формат хранения (LLM-friendly JSON envelope) остаётся прежним:

 - `schema`: `neuronapp.var.v1`
- `sessionKey`: ключ сессии
- `label`: метка
- `savedAt`: ISO‑8601 дата/время сохранения
- `dataType`: `string|object|array|number|boolean|null`
- `data`: сохранённое значение (JSON‑совместимое)

Индекс хранит список элементов (без `data`) для `VarListTool`.

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

Конфигурации агентов в `agents/*.php` и `testapp/agents/*.php` должны использовать именно `ShellToolFactory`, а не напрямую создавать `BashTool`/`BashCmdTool`.

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

### `TodoGotoTool` (`todo_goto`)

Назначение:

- дать LLM явный инструмент для изменения порядка выполнения списка TodoList;
- не делать "прыжок" сразу в инструменте, а безопасно передать запрос в run-state.

Параметры:

- `point` (integer, required) — номер пункта TodoList в **1-based** нумерации;
- `reason` (string, optional) — краткая причина перехода.

Поведение:

- инструмент читает активный `RunStateDto` через `ConfigurationAgent::getExistRunStateDto()`;
- при валидном вызове пишет `goto_requested_todo_index` (0-based) в checkpoint;
- сам переход выполняется в `TodoList::execute()` после завершения текущего todo;
- если run-state отсутствует (например, вне запуска TodoList), инструмент возвращает `success=false`.

Формат ответа (`TodoGotoResultDto`):

- `success` (bool) — признак успешности запроса;
- `message` (string) — пояснение результата;
- `fromPoint` (int|null) — текущий пункт в 1-based нумерации или `null`, если определить нельзя;
- `toPoint` (int|null) — целевой пункт в 1-based нумерации, как передан в инструмент;
- `reason` (string|null) — нормализованная причина перехода.

Пример псевдо-вызова:

```json
{"tool":"todo_goto","args":{"point":2,"reason":"вернуться к проверке"}}
```

### `TodoCompletedTool` (`todo_completed`)

Назначение:

- дать шагу `step` в сценарии TodoList явный механизм выставления флага завершения;
- сохранить в `.store` каноничное значение `completed = 1|0`, чтобы внешний оркестратор завершал/продолжал цикл детерминированно.

Параметры:

- `status` (string, required) — одно из значений:
  - завершено: `done`, `1`, `true`, `исполнено`;
  - не завершено: `not_done`, `0`, `false`, `не исполнено`.
- `reason` (string, optional) — краткая причина изменения статуса.

Source of truth:

- `src/helpers/TodoCompletedStatusHelper.php`
- `src/tools/TodoCompletedTool.php`
- `src/classes/orchestrators/TodoListOrchestrator.php`

Поведение:

- инструмент нормализует `status` к `1|0` через `TodoCompletedStatusHelper::normalize()`;
- записывает значение в `VarStorage` под меткой `completed`;
- возвращает LLM-friendly JSON через `VarToolResultDto` (`action=todo_completed`).

Инварианты:

- `TodoCompletedTool` и `TodoListOrchestrator` обязаны трактовать `completed` одинаково;
- если набор допустимых строк меняется, он меняется только в `TodoCompletedStatusHelper`;
- docs и тексты ошибок не должны перечислять значения вручную вне этого helper.

Пример псевдо-вызова:

```json
{"tool":"todo_completed","args":{"status":"done","reason":"обработка файла завершена"}}
```

### Семантическое чанкование Markdown

В `src/helpers/MarkdownChunckHelper.php` добавлен метод:

- `chunkBySemanticBlocks(string $markdown, int $targetChars): MarkdownChunksResultDto`
- `chunkAroundAnchorLineRegex(string $markdown, int $fromChar, string $lineRegex, int $maxChars = 5000): ?MarkdownChunkDto`
- `chunksAroundAllAnchorLineRegex(string $markdown, string $lineRegex, int $maxCharsPerBlock = 5000, int $maxTotalChars = 5000): MarkdownChunksResultDto`

### `ChunckGrepTool` (`chunk_grep`)

Назначение:

- найти совпадения в текстовом файле и вернуть **семантические чанки markdown** вокруг строк-якорей.
- полезно для “быстрого контекста” вокруг совпадений без чтения всего файла.

Параметры:

- `path` (string, required) — путь к файлу.
- `query` (string, required) — regex (с разделителями) или обычная строка (будет преобразована в regex).
- `max_chars` (integer, required) — максимальный суммарный размер возвращаемого контента.

Пример псевдо-вызова:

```json
{"tool":"chunk_grep","args":{"path":"docs/tools.md","query":"chat_history","max_chars":2000}}
```

Назначение:

- разбивает markdown-текст на чанки, ориентируясь на целевой размер в символах;
- сохраняет семантические блоки целыми (таблицы, fenced code block, абзацы, списки, заголовки);
- для длинных текстовых блоков допускает деление по предложениям без разрыва слов;
- допускает недобор/перебор относительно `targetChars`, если это требуется для читаемости.

Дополнительно:

- `chunkAroundAnchorLineRegex(...)` находит **первую строку**, совпадающую с `lineRegex` (regex/строка **или массив** таких паттернов) начиная с `fromChar`,
  и возвращает окно семантических блоков вокруг якоря. Окно строится из **целых блоков**
  до/после якоря так, чтобы якорь оказался примерно в середине, с ограничением `maxChars`.
  Если якорный семантический блок больше `maxChars`, возвращается этот блок целиком.
- Если `lineRegex` передан обычной фразой (не regex), helper строит "разреженный" паттерн:
  - из фразы извлекаются слова (`[\p{L}\p{N}_]+`), удаляются одиночные буквы;
  - слова длиной `<= 4` не обрезаются;
  - для слов длиной `> 4` применяется гибридная обрезка окончания:
    1) сначала пробуется список типовых русских окончаний;
    2) иначе fallback по длине (минус 1 символ для слов на гласную/`й`/`ь`).
  - между соседними словами допускается до 5 промежуточных слов.
- Пример:
  - фраза: `коэффициент локализация производимая продукция расчеты результаты`
  - regex: `/коэффициент[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+локализаци[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+производ[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+продукци[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+расчет[\p{L}\p{N}_]*(?:[\s\pP]+[\p{L}\p{N}_]+){0,5}[\s\pP]+результат[\p{L}\p{N}_]*/ui`

- `chunksAroundAllAnchorLineRegex(...)` возвращает **набор** непересекающихся чанков вокруг **всех**
  вхождений строк по `lineRegex` (regex/строка **или массив** таких паттернов). Каждый чанк строится из **целых** семантических блоков и ограничен
  `maxCharsPerBlock`, а суммарный размер всех чанков ограничен `maxTotalChars`. Если новый чанк
  пересекается по блокам с уже выбранными — он пропускается.

Формат результата:

- `MarkdownChunksResultDto`:
  - `targetChars` — целевой размер чанка;
  - `totalChunks` — количество чанков;
  - `totalChars` — суммарный размер текста в чанках;
  - `chunks` — массив `MarkdownChunkDto`.
- `MarkdownChunkDto`:
  - `index` — индекс чанка (0-based);
  - `text` — текст чанка;
  - `lengthChars` — длина текста в символах;
  - `blockKinds` — типы блоков, вошедших в чанк;
  - `isOversized` — чанк превышает `targetChars`.