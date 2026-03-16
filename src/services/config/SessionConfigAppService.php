<?php

namespace app\modules\neuron\services\config;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\run\RunStateDto;
use app\modules\neuron\classes\dto\session\SessionListItemDto;
use app\modules\neuron\classes\dto\session\SessionStatusDto;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use app\modules\neuron\classes\neuron\history\FileFullChatHistory;
use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
use app\modules\neuron\helpers\ChatHistoryEditHelper;
use app\modules\neuron\helpers\RunStateCheckpointHelper;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\Message;
use RuntimeException;

/**
 * Сервис управления сессиями приложения.
 *
 * Под «сессией» понимается:
 * - файл истории диалога `.sessions/neuron_<sessionKey>.chat`;
 * - (опционально) чекпоинт состояния выполнения TodoList `.store/run_state_{sessionKey}_{agent}.json`.
 *
 * Сервис предоставляет операции:
 * - список сессий;
 * - получение/удаление конкретной сессии;
 * - получение статуса выполнения run (RunStateDto);
 * - получение количества сообщений;
 * - удаление/вставка сообщений по индексу в полной истории;
 * - получение копии истории, обрезанной заданным {@see HistoryTrimmerInterface}.
 *
 * Пример использования:
 *
 * <code>
 * $srv = ConfigurationApp::getInstance()->getSessionService();
 *
 * $items = $srv->list();
 * $status = $srv->getStatus('20250301-143022-123456');
 *
 * $count = $srv->getMessageCount('20250301-143022-123456');
 * $srv->deleteMessage('20250301-143022-123456', 0);
 * </code>
 */
class SessionConfigAppService
{
    public function __construct(protected ConfigurationApp $_configApp)
    {
    }

    /**
     * Возвращает список сессий, найденных в директории `.sessions`.
     *
     * @return SessionListItemDto[]
     */
    public function list(): array
    {
        $dir = $this->getSessionDirOrFail();

        $paths = glob($dir . DIRECTORY_SEPARATOR . 'neuron_*.chat') ?: [];

        usort($paths, static function (string $a, string $b): int {
            return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
        });

        $result = [];
        foreach ($paths as $path) {
            $base = basename($path);
            if (!str_starts_with($base, 'neuron_') || !str_ends_with($base, '.chat')) {
                continue;
            }

            $sessionKey = substr($base, 6, -5); // len('neuron_')=6, len('.chat')=5
            $sessionKey = ltrim($sessionKey, '_');

            $hasCheckpoint = RunStateCheckpointHelper::read($sessionKey, RunStateDto::DEF_AGENT_NAME) !== null;

            $result[] = (new SessionListItemDto())
                ->setSessionKey($sessionKey)
                ->setChatFilePath($path)
                ->setUpdatedAt((int) (filemtime($path) ?: 0))
                ->setSizeBytes((int) (filesize($path) ?: 0))
                ->setHasRunCheckpoint($hasCheckpoint);
        }

        return $result;
    }

    /**
     * Возвращает файловую историю для указанного ключа сессии.
     *
     * @param string $sessionKey Ключ сессии.
     */
    public function get(string $sessionKey): FileFullChatHistory
    {
        return new FileFullChatHistory(
            directory: $this->getSessionDirOrFail(),
            key: $sessionKey,
        );
    }

    /**
     * Удаляет историю сессии (файл `.sessions/neuron_<sessionKey>.chat`).
     *
     * Чекпоинт `.store/run_state_*` по умолчанию не трогает.
     *
     * @param string $sessionKey Ключ сессии.
     */
    public function delete(string $sessionKey): void
    {
        $path = $this->buildChatFilePath($sessionKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Возвращает статус выполнения run (TodoList) для сессии.
     *
     * @param string $sessionKey Ключ сессии.
     */
    public function getStatus(string $sessionKey): SessionStatusDto
    {
        $runState = RunStateCheckpointHelper::read($sessionKey, RunStateDto::DEF_AGENT_NAME);

        return (new SessionStatusDto())
            ->setSessionKey($sessionKey)
            ->setRunState($runState);
    }

    /**
     * Возвращает количество сообщений в полной истории сессии.
     *
     * @param string $sessionKey Ключ сессии.
     */
    public function getMessageCount(string $sessionKey): int
    {
        $history = $this->get($sessionKey);
        return count($history->getFullMessages());
    }

    /**
     * Удаляет сообщение из полной истории сессии по индексу.
     *
     * @param string $sessionKey Ключ сессии.
     * @param int $index Индекс сообщения (0..n-1).
     */
    public function deleteMessage(string $sessionKey, int $index): void
    {
        $history = $this->get($sessionKey);
        ChatHistoryEditHelper::deleteFullMessageAt($history, $index);
    }

    /**
     * Вставляет сообщение в полную историю сессии по индексу.
     *
     * @param string $sessionKey Ключ сессии.
     * @param int $index Индекс вставки (0..n).
     * @param Message $message Сообщение для вставки.
     */
    public function insertMessage(string $sessionKey, int $index, Message $message): void
    {
        $history = $this->get($sessionKey);
        ChatHistoryEditHelper::insertFullMessageAt($history, $index, $message);
    }

    /**
     * Возвращает копию истории, пропущенную через заданный {@see HistoryTrimmerInterface}.
     *
     * Метод не изменяет файл истории сессии. Он создаёт in-memory копию полной истории
     * и формирует окно (`getMessages()`) через переданный триммер.
     *
     * @param string $sessionKey Ключ сессии.
     * @param HistoryTrimmerInterface $trimmer Триммер окна сообщений.
     * @param int $contextWindow Размер контекстного окна в токенах.
     *
     * @return AbstractFullChatHistory In-memory история с полной проекцией и окном, сформированным триммером.
     */
    public function getTrimmedHistory(
        string $sessionKey,
        HistoryTrimmerInterface $trimmer,
        int $contextWindow = 50000
    ): AbstractFullChatHistory {
        $fileHistory = $this->get($sessionKey);

        $copy = new InMemoryFullChatHistory(contextWindow: $contextWindow, trimmer: $trimmer);
        foreach ($fileHistory->getFullMessages() as $msg) {
            $copy->addMessage($msg);
        }

        return $copy;
    }

    /**
     * Возвращает директорию `.sessions` или выбрасывает исключение.
     */
    private function getSessionDirOrFail(): string
    {
        $dir = $this->_configApp->getSessionDir();
        if (!is_string($dir) || $dir === '') {
            throw new RuntimeException('Директория сессий (.sessions) не найдена.');
        }
        return $dir;
    }

    /**
     * Формирует путь к файлу истории сессии.
     *
     * @param string $sessionKey Ключ сессии.
     */
    private function buildChatFilePath(string $sessionKey): string
    {
        return $this->getSessionDirOrFail()
            . DIRECTORY_SEPARATOR
            . 'neuron_'
            . $sessionKey
            . '.chat';
    }
}
