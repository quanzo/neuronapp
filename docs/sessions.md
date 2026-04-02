## Сессии приложения

Этот документ описывает, как в проекте устроены сессии и как ими управлять через `SessionConfigAppService`.

### Что такое «сессия»

В контексте проекта сессия включает два независимых слоя:

- **история диалога** — файл в `.sessions` с именем `neuron_<sessionKey>.chat`;
- **статус выполнения TodoList (run)** — чекпоинт в `.store` с именем `run_state_{sessionKey}_{agent}.json`.

История диалога реализована через `FileFullChatHistory` и хранит **полную историю** + **окно для LLM**:

- полная история: `getFullMessages()`
- окно: `getMessages()` (формируется триммером под контекстное окно модели)

В коде проекта чтение сообщений истории для утилит/инструментов централизовано через
`ChatHistoryEditHelper::getMessages()`: если история поддерживает полную проекцию
(`AbstractFullChatHistory`), берётся полная история, иначе — окно.

### Очистка истории от `chat_history.*`

LLM может вызывать инструменты просмотра истории (`chat_history.*`) и получать ответы, содержащие фрагменты истории. Чтобы файл `.sessions/neuron_<sessionKey>.chat` не разрастался копиями, в `FileFullChatHistory` предусмотрен метод:

- `purgeHistoryInspectionTools()` — удаляет из полной истории tool-call/tool-result, относящиеся к `chat_history.size`, `chat_history.meta`, `chat_history.message`, пересобирает окно и сохраняет файл истории.

### Где лежат файлы

- `.sessions/neuron_<sessionKey>.chat` — история диалога (см. `FileFullChatHistory`).
- `.store/run_state_<sessionKey>_<agent>.json` — чекпоинт статуса выполнения run (см. `RunStateCheckpointHelper` и `RunStateDto`).

### Очистка сессий из CLI

Для удаления файлов, связанных с сессией, доступны команды:

- `session:clear` — удалить одну сессию по `--session_id`;
- `sessions:clear` — удалить все сессии (ключи собираются как union по `.sessions/.store/.logs`).

Удаляются файлы:

- `.sessions/neuron_<sessionKey>*.chat`
- `.store/run_state_<sessionKey>_*.json`
- `.store/var_<sessionKey>_*.json` и `.store/var_index_<sessionKey>.json`
- `.logs/<sessionKey>.log`

Дополнительно в checkpoint TodoList могут храниться поля управления переходами:

- `goto_requested_todo_index` — индекс пункта, куда нужно перейти после текущего шага (записывается `todo_goto`);
- `goto_transitions_count` — число уже применённых переходов в текущем запуске.

Рабочие директории разрешаются через `DirPriority` (см. `docs/directories.md`).

### API `SessionConfigAppService`

Класс: `src/services/config/SessionConfigAppService.php`.

Методы:

- `list(): SessionListItemDto[]` — список сессий по файлам `.sessions/neuron_*.chat` (+ признак чекпоинта run).
- `get(string $sessionKey): FileFullChatHistory` — получение файловой истории сессии.
- `delete(string $sessionKey): void` — удаление файла истории (чекпоинты `.store` не трогаются).
- `getStatus(string $sessionKey): SessionStatusDto` — статус run по чекпоинту `RunStateDto` (если чекпоинта нет — `runState=null`).
- `getMessageCount(string $sessionKey): int` — количество сообщений в полной истории.
- `deleteMessage(string $sessionKey, int $index): void` — удалить сообщение из полной истории по индексу.
- `insertMessage(string $sessionKey, int $index, Message $message): void` — вставить сообщение в полную историю по индексу.
- `getTrimmedHistory(string $sessionKey, HistoryTrimmerInterface $trimmer, int $contextWindow = 50000): AbstractFullChatHistory` — получить **копию** истории, где окно построено переданным триммером (исходный файл не изменяется).

### Пример

```php
use app\modules\neuron\classes\config\ConfigurationApp;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

$srv = ConfigurationApp::getInstance()->getSessionService();

$items = $srv->list();
$status = $srv->getStatus($items[0]->getSessionKey());

$count = $srv->getMessageCount($items[0]->getSessionKey());

// Вставка сообщения в полную историю
$srv->insertMessage($items[0]->getSessionKey(), 0, new Message(MessageRole::USER, 'Hello'));
```

