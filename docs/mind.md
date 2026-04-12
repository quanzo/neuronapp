# Долговременная память сообщений (`.mind`)

Каталог `.mind` в рабочем окружении приложения хранит Markdown-файлы с перепиской по пользователю. Запись выполняется подписчиком `LongTermMindSubscriber` по событию `agent.message.completed`.

## Расположение

- Путь: `ConfigurationApp::getMindDir()` → подкаталог `.mind` в одной из баз `DirPriority`.
- Создание каталога при старте CLI: `bin/console.php` (тот же цикл `mkdir`, что для `.sessions`, `.logs`, `.store`).
- Имя файла данных: базовое имя из `MindStorageFilenameHelper::toBasename(userId)` + `.md`; рядом — `.mind.idx`, `.mind.seq`, `.mind.lock`.

## Формат блока в `.md`

Между блоками — **две пустые строки** (`\n\n\n\n`).

Структура одного блока:

1. Одна строка заголовка с полями через таб: `recordId`, время ISO-8601, `sessionKey`, роль (строка как у NeuronAI).
2. Пустая строка.
3. Тело сообщения в UTF-8; внутри тела последовательности из трёх и более переводов строк схлопываются до двух, чтобы не появлялись «двойные пустые строки» внутри текста.

## Индекс и доступ

Класс `UserMindMarkdownStorage` (`src/classes/storage/UserMindMarkdownStorage.php`):

- не читает весь `.md` в память при выборке одной записи;
- хранит в `.mind.idx` строки `recordId`, байтовое смещение и длину блока;
- `getByRecordId()` использует **бинарный поиск** по `recordId` в индексе, затем `fseek`/`fread` по `.md`;
- все мутации и перестроения — под `flock` на файле `<basename>.mind.lock`.

Методы: добавление записи, чтение по id, удаление и замена по id, оценка среза по списку id (`estimateSlice`) в символах UTF-8 и токенах через `TokenCounter`.

Запись в памяти приложения как структура данных: единый DTO `MindRecordDto` (`src/classes/dto/mind/MindRecordDto.php`) — и для результата `getByRecordId()`, и для элементов списка в `replaceByRecordIds()`.

## Событие и фильтрация

- Событие: `EventNameEnum::AGENT_MESSAGE_COMPLETED` (`agent.message.completed`).
- DTO: `AgentMessageEventDto` — `outgoingMessage` (отправленное в агент), `incomingMessage` (ответ ассистента как `NeuronMessage`, если есть: из `chat()` или последнее assistant-сообщение из истории после `structured()`), плюс `attachmentsCount`, `structured`, `durationSeconds`, базовые поля `BaseEventDto`.
- Публикация: `ConfigurationAgent::dispatchMessageToAgent()` после успешного `performAgentRequest()` (включая structured и обычный `chat()`), а также после успешного wait-cycle без исключения (в этом случае `incomingMessage` может быть null).
- Идентификатор пользователя для файлов `.mind` берётся из `ConfigurationApp::getUserId()` внутри подписчика.
- Глобально сбор в `.mind` можно отключить в `config.jsonc`: `mind.collect: false` (или вложенный объект `"mind": { "collect": false }`). Тогда `ConfigurationApp::isLongTermMindCollectionEnabled()` возвращает `false`, и подписчик не пишет в storage (по умолчанию опция включена).
- Если у `AgentMessageEventDto::getAgent()` выставлено `ConfigurationAgent::isExcludeLongTermMind() === true`, подписчик **полностью пропускает** запись (используется при исполнении Skill/TodoList с опцией `pure_context: true`, см. `docs/skills.md`, `docs/todolist.md`).
- `LongTermMindSubscriber` не пишет сообщения, распознанные как служебные циклом `LlmCycleHelper` (`isCycleEmptyMsg`, `isCycleRequestMsg`, `isCycleResponseMsg`), и не пишет пустой текст после нормализации тела.

Регистрация подписчика: `AbstractAgentCommand::resolveFileLogger()` рядом с остальными logging-subscribers.
