## Сессии приложения

Этот документ описывает, как в проекте устроены сессии и как ими управлять через `SessionConfigAppService`.

### Что такое «сессия»

В контексте проекта сессия включает два независимых слоя:

- **история диалога** — файл в `.sessions` с именем `neuron_<sessionKey>.chat`;
- **статус выполнения TodoList (run)** — чекпоинт в `.store` с именем `run_state_{sessionKey}_{agent}.json`.

История диалога реализована через `FileFullChatHistory` и хранит **полную историю** + **окно для LLM**:

- полная история: `getFullMessages()`
- окно: `getMessages()` (формируется триммером под контекстное окно модели)

### Где лежат файлы

- `.sessions/neuron_<sessionKey>.chat` — история диалога (см. `FileFullChatHistory`).
- `.store/run_state_<sessionKey>_<agent>.json` — чекпоинт статуса выполнения run (см. `RunStateCheckpointHelper` и `RunStateDto`).

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

