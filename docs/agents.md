## Агенты и producers

Этот документ описывает, как в проекте устроены агенты LLM (`ConfigurationAgent`) и фабрика агентов (`AgentProducer`).

### Что такое агент

Агент — это конфигурация LLM, через которую выполняются skills и todolist:

- задаёт провайдера (модель, ключи доступа и т.д.);
- определяет размер контекста (`contextWindow`);
- управляет историей чата (`enableChatHistory`);
- содержит системный промпт (`instructions`);
- объявляет доступные инструменты (`tools`, `mcp`, RAG‑компоненты).

Реализация: `src/classes/config/ConfigurationAgent.php`.

Основные возможности:

- создание и кеширование `AgentInterface` (обычный агент или RAG‑агент);
- отправка сообщений с вложениями (`sendMessageWithAttachments()`);
- повтор при ошибке провайдера: вызов обёрнут в `WaitSuccess`, после каждой неудачной попытки в `funcAfterError` вызывается `ChatHistoryRollbackHelper::rollbackToSnapshot()` к снимку размера истории до попытки (`getSnapshotCount()`), чтобы NeuronAI не оставлял дубликат пользовательского сообщения в истории; факт ошибки, номер попытки и то, будет ли ещё повтор, пишутся в лог уровня `warning` (контекст `failedAttempt`, `maxAttempts`, `willRetry`, класс и текст ошибки);
- управление историей чата (`getChatHistory()`, `setChatHistory()`, `resetChatHistory()`);
- работа с состоянием запуска todolist (`getBlankRunStateDto()`, `getExistRunStateDto()`);
- клоны конфигурации для отдельных запусков (`cloneForSession()`).

### Файлы конфигураций агентов (`agents/*.php`, `agents/*.jsonc`)

Файлы агентов хранятся в директории `agents/`, расположенной в приоритетных директориях `DirPriority` (обычно `APP_START_DIR/agents` и `APP_WORK_DIR/agents`).

Поддерживаемые форматы:

- PHP‑файл, возвращающий массив настроек (например, `agents/neuron1.php`);
- JSONC‑файл (`.jsonc`), содержащий аналогичную структуру (например, `agents/neuron1.jsonc`).

При совпадении имён файлов (`neuron1.php` и `neuron1.jsonc`) приоритет у PHP‑файла.

Создание конфигурации:

- `ConfigurationAgent::makeFromFile(string $filename, ConfigurationApp $configApp): ?ConfigurationAgent`:
  - читает PHP или JSONC‑файл;
  - удаляет комментарии JSONC через `CommentsHelper::stripComments()`;
  - передаёт массив настроек в `makeFromArray()`;
- `ConfigurationAgent::makeFromArray(array $cfg, ConfigurationApp $configApp): ?ConfigurationAgent`:
  - заполняет свойства агента по известным ключам (`enableChatHistory`, `contextWindow`, `provider`, `instructions`, `tools`, `mcp`, `embeddingProvider`, `vectorStore` и т.д.);
  - устанавливает `ConfigurationApp` и `sessionKey` (через `configApp->getSessionKey()`).

### `AgentProducer` — фабрика агентов

Класс: `src/classes/producers/AgentProducer.php` (наследник `AProducer`).

Задачи:

- искать файлы конфигурации агентов в поддиректории `agents` через `DirPriority`;
- кэшировать уже созданные конфигурации по имени агента;
- возвращать строго `ConfigurationAgent` или `null`.

Ключевые особенности:

- `getStorageDirName()` возвращает `'agents'`;
- `getExtensions()` — `['php', 'jsonc']`;
- `createFromFile()`:
  - вызывает `ConfigurationAgent::makeFromFile()` с `ConfigurationApp`;
  - выставляет `agentName` в имя файла;
  - если `enableChatHistory === false`, сбрасывает историю (`resetChatHistory()`);
  - добавляет логгер, если он не задан (`FileLogger` через `LoggerAwareTrait`);
- `get(string $name): ?ConfigurationAgent`:
  - использует кэш `AProducer`;
  - конвертирует результат к `ConfigurationAgent` или `null`.

### Связь агентов с другими компонентами

- Skills и todolist:
  - по имени агента получают конфигурацию через `ConfigurationApp::getAgent($name)`;
  - могут переопределять агента через опцию `agent` в YAML‑шапке (`AbstractPromptWithParams::getConfigurationAgent()`).
- Консольные команды:
  - `simplemessage` и `todolist` используют `ConfigurationApp` для:
    - проверки `session_id` (`isValidSessionKey()`, `sessionExists()`);
    - установки `sessionKey` в конфигурации приложения (и, через неё, в агента);
    - получения конфигурации агента по имени;
  - при необходимости работают с `RunStateDto` через методы агента.

Подробнее о конфигурации приложения и сессиях см. `docs/config.md`, о skills и todolist — в `docs/skills.md` и `docs/todolist.md`.

### Опрос «задача завершена?» и очистка истории (`LlmCycleHelper`)

После выполнения пункта списка задач или skill вызывается `LlmCycleHelper::waitCycle(ConfigurationAgent, maxCycleCount, maxTotalRounds)` — цикл прямых вопросов к модели, пока не будет получено явное подтверждение завершения. Классификация ответа — статический метод `LlmCyclePollStatus::fromAgentAnswer()` (`src/enums/LlmCyclePollStatus.php`), возвращающий перечисление: **Completed** (в первой непустой строке текста слово YES), **InProgress** (явные NO или WAITING), **Unclear** (пусто, только tool-call, прочий текст). Ответ не-сообщением (например DTO) считается Completed. Невнятные ответы не увеличивают счётчик «явных незавершений», но каждый раунд `sendMessage` учитывается; при превышении `maxTotalRounds` (по умолчанию `max(30, maxCycleCount * 6)`) цикл прерывается, чтобы не зациклиться. Возвращаемый массив содержит `ok`, `cycles` (число раундов опроса), `clearProgressCount`, `totalRounds`.

Чтобы в сохранённой истории сессии не копились служебные реплики проверки, после **каждого** раунда «user: запрос статуса → assistant: ответ» выполняется очистка:

- классификация ответа: `LlmCycleStatusCheckHelper::resolveCleanupDecision()`;
- применение: `StatusCheckHistoryCleanupHelper::apply()` с учётом типа истории (`AbstractFullChatHistory` через `ChatHistoryEditHelper`, `InMemoryChatHistory` через `ChatHistoryTruncateHelper` / `deleteMessageAtIndex()`).

Правила: явные слова **YES**, **NO**, **WAITING** (как отдельные слова латиницей, без ложных срабатываний вроде подстроки в «KNOW»), пустой ответ ассистента или не-текстовый (структурированный) ответ — из истории убирается **пара** сообщений; неоднозначный текст — удаляется **только** пользовательское сообщение с проверкой, ответ модели остаётся. Если `getContent()` у ответа — **непустой массив** контент-блоков (нестандартная или будущая форма), служебную пару **не** вычищаем (`LlmCycleStatusCheckHelper::decisionForNeuronRawAndNormalizedText()` возвращает отказ от очистки). Решение зафиксировано в перечислении `StatusCheckCleanupDecision` (`src/enums/StatusCheckCleanupDecision.php`).

