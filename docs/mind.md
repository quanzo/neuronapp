# Долговременная память сообщений (`.mind`)

Каталог `.mind` в рабочем окружении приложения хранит Markdown-файлы с перепиской пользователя. Запись выполняется подписчиком `LongTermMindSubscriber` по событию `agent.message.completed`.

Начиная с версии **per-session** формат изменён: вместо одного файла на пользователя создаётся **отдельное хранилище на каждую сессию**. Это даёт агентам возможность точнее выбирать релевантный контекст (например, найти факт из ранней сессии и применить его в поздней).

## Расположение

- Путь: `ConfigurationApp::getMindDir()` → подкаталог `.mind` в одной из баз `DirPriority`.
- Создание каталога при старте CLI: `bin/console.php` (тот же цикл `mkdir`, что для `.sessions`, `.logs`, `.store`).

### Новый layout (per-session)

В корне `.mind` создаётся поддиректория пользователя:

- `.mind/<userBasename>/`, где `userBasename = MindStorageFilenameHelper::toBasename(userId)`

Внутри user-директории:

- `sessions.md` — индекс сессий пользователя (Markdown, машинно-парсимый);
- `sessions/` — файлы сессионных хранилищ.

Для каждой сессии создаётся набор файлов:

- `.mind/<userBasename>/sessions/<storageKey>.md`
- `.mind/<userBasename>/sessions/<storageKey>.mind.idx`
- `.mind/<userBasename>/sessions/<storageKey>.mind.seq`
- `.mind/<userBasename>/sessions/<storageKey>.mind.lock`

Где:

- `storageKey` — безопасный ключ, получаемый из `sessionKey` (см. `MindSessionStorageKeyHelper`), чтобы исключить опасные символы в именах файлов.

### Индекс сессий `sessions.md`

Файл `.mind/<userBasename>/sessions.md` имеет строгую схему:

- первая непустая строка: `schema: neuronapp.mind.sessions.v1`
- далее таблица Markdown:

`| sessionKey | firstCapturedAt | lastCapturedAt | messageCount | summary | storageKey |`

`summary` хранится в однострочном виде и предназначено для быстрого «сканирования» сессий человеком и агентом.

## Формат блока в `.md`

Между блоками — **две пустые строки** (`\n\n\n\n`).

Структура одного блока:

1. Одна строка заголовка с полями через таб: `recordId`, время ISO-8601, `sessionKey`, роль (строка как у NeuronAI).
2. Пустая строка.
3. Тело сообщения в UTF-8; внутри тела последовательности из трёх и более переводов строк схлопываются до двух, чтобы не появлялись «двойные пустые строки» внутри текста.

## Индекс и доступ

Legacy класс `UserMindMarkdownStorage` (`src/classes/storage/UserMindMarkdownStorage.php`):

- не читает весь `.md` в память при выборке одной записи;
- хранит в `.mind.idx` строки `recordId`, байтовое смещение и длину блока;
- `getByRecordId()` использует **бинарный поиск** по `recordId` в индексе, затем `fseek`/`fread` по `.md`;
- все мутации и перестроения — под `flock` на файле `<basename>.mind.lock`.

Методы: добавление записи, чтение по id, удаление и замена по id, оценка среза по списку id (`estimateSlice`) в символах UTF-8 и токенах через `TokenCounter`, **поиск блоков** `searchBlocks(string $query, ?int $maxChars = 100000): list<MindRecordDto>`.

### Новый storage (per-session)

Новый storage для одной сессии: `SessionMindMarkdownStorage` (`src/mind/storage/SessionMindMarkdownStorage.php`).

Он сохраняет тот же формат блоков, но:

- работает в рамках одного `sessionKey`;
- `recordId` монотонен **в пределах одной сессии** (начиная с 1).

Высокоуровневый API пользователя: `UserMindStorage` (`src/mind/storage/UserMindStorage.php`) — выбирает session-storage, обновляет `sessions.md` и выполняет миграцию legacy (см. ниже).

### Поиск `searchBlocks`

- **query** — строка в том же смысле, что у инструмента `chunk_grep`: регулярное выражение с разделителями (например `/TODO/u`) или обычный текст; нормализация через `MarkdownChunckHelper::buildLineRegex()`.
- **maxChars** — максимум суммарной длины возвращаемых блоков в символах UTF-8 (по сырому тексту блока в файле); `null` — без ограничения; по умолчанию `100000`. Блоки целиком не включаются, если не помещаются в остаток лимита; перебор идёт в порядке релевантности (число совпадений, затем суммарная длина совпадений, затем `recordId`).
- Совпадения ищутся по полному тексту блока (строка заголовка и тело).

Запись в памяти приложения как структура данных: единый DTO `MindRecordDto` (`src/classes/dto/mind/MindRecordDto.php`) — и для результата `getByRecordId()`, и для элементов списка в `replaceByRecordIds()`.

## Событие и фильтрация

- Событие: `EventNameEnum::AGENT_MESSAGE_COMPLETED` (`agent.message.completed`).
- DTO: `AgentMessageEventDto` — `outgoingMessage` (отправленное в агент), `incomingMessage` (ответ ассистента как `NeuronMessage`, если есть: из `chat()` или последнее assistant-сообщение из истории после `structured()`), плюс `attachmentsCount`, `structured`, `durationSeconds`, базовые поля `BaseEventDto`.
- Публикация: `ConfigurationAgent::dispatchMessageToAgent()` после успешного `performAgentRequest()` (включая structured и обычный `chat()`), а также после успешного wait-cycle без исключения (в этом случае `incomingMessage` может быть null).
- Идентификатор пользователя для файлов `.mind` берётся из `ConfigurationApp::getUserId()` внутри подписчика.
- Глобально сбор в `.mind` включается в `config.jsonc`: `mind.collect: true` (или `"mind": { "collect": true }`). По умолчанию (`mind.collect` отсутствует) сбор **выключен** — `ConfigurationApp::isLongTermMindCollectionEnabled()` возвращает `false`, подписчик не пишет в storage.
- Если у `AgentMessageEventDto::getAgent()` выставлено `ConfigurationAgent::isExcludeLongTermMind() === true`, подписчик **полностью пропускает** запись (используется при исполнении Skill/TodoList с опцией `pure_context: true`, см. `docs/skills.md`, `docs/todolist.md`).
- `LongTermMindSubscriber` не пишет сообщения, распознанные как служебные циклом `LlmCycleHelper` (`isCycleEmptyMsg`, `isCycleRequestMsg`, `isCycleResponseMsg`), и не пишет пустой текст после нормализации тела.

## Миграция legacy → per-session

При первой записи в mind (в подписчике) выполняется проверка:

- если в корне `.mind` присутствуют legacy файлы пользователя (`<userBasename>.md` и `<userBasename>.mind.idx`) и при этом **не существует** `.mind/<userBasename>/sessions.md`, запускается миграция.

Мигратор: `LegacyUserMindMigrator` (`src/mind/storage/LegacyUserMindMigrator.php`).

Инварианты миграции:

- legacy файлы **не удаляются** (остаются как backup);
- записи переносятся в per-session файлы по `sessionKey` из заголовка блока;
- `recordId` в новых файлах назначаются заново (монотонно в пределах каждой сессии).

## Summary сессий через LLM

В индексе `sessions.md` поле `summary` может заполняться автоматически через LLM-агента.

Конфигурация в `config.jsonc`:

```jsonc
{
  "mind": {
    "session_summary": {
      "agent": "my_summarizer_agent",
      "max_summary_chars": 300,
      "transcript_ratio": 0.25
    }
  }
}
```

- если `mind.session_summary.agent` не задан — summary остаётся пустым;
- для ограничения контекста используется тримминг истории по токенам.

### API суммаризации (`UserMindStorage`)

Запуск LLM-summary вынесен в [`UserMindStorage`](src/mind/storage/UserMindStorage.php) (внутри — [`MindSessionSummaryService`](src/mind/services/MindSessionSummaryService.php)):

```php
$paths = new MindPaths($app->getMindDir(), $app->getUserId());
$mind = new UserMindStorage($paths);

// Одна сессия: true, если summary непустой и изменился
$updated = $mind->refreshSessionSummary($app, $sessionKey);

// Все сессии индекса (пропуск служебных ключей и пустых)
$result = $mind->refreshAllSessionSummaries($app);
// $result->getAttempted(), getUpdated(), getSkipped()
```

Автоматически при записи сообщений: `LongTermMindSubscriber` вызывает `refreshSessionSummary` по эвристике (пустой summary или каждые 10 сообщений).

### Защита от зацикливания (mind-summary)

Служебный LLM-вызов суммаризатора публикует `agent.message.completed` так же, как основной агент. Чтобы не писать промпт/ответ суммаризации в транскрипт основной сессии и не запускать повторный `refreshSessionSummary`, используется комбинация:

1. **`ConfigurationAgent::setExcludeLongTermMind(true)`** на клоне агента-суммаризатора — `LongTermMindSubscriber` полностью пропускает запись.
2. **Отдельный sessionKey** — `MindSummarySessionKeyHelper::forMainSession($main)` → `$main:__mind_summary__` (если exclude сброшен, сообщения попадают только в служебную сессию, не в MAIN).
3. **Подписчик** — не вызывает `refreshSessionSummary` для ключей с суффиксом `:__mind_summary__`; re-entrancy guard (`$summaryRefreshDepth`) при вложенном refresh.
4. **Инструменты** — `mind.sessions` и `mind.search` не показывают служебные сессии (`isSummarySession`).

Классы: `MindSummarySessionKeyHelper`, `MindSessionSummaryService`, `ConfigurationAgentHistoryHeadSummarizer` (проброс exclude во внутренний клон), `LongTermMindSubscriber`.

## Инструменты LLM для `.mind`

Встроенные tools (через `ToolRegistry`):

- `mind.sessions` — список сессий пользователя (метаданные + summary);
- `mind.search` — поиск по всем сессиям пользователя (regex или текст);
- `mind.session.view` — просмотр одной сессии: по `recordId` или последние N сообщений.

Регистрация подписчика: `AbstractAgentCommand::resolveFileLogger()` рядом с остальными logging-subscribers.
