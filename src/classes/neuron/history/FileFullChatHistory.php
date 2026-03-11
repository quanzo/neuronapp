<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\history;

use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Exceptions\ChatHistoryException;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

/**
 * Реализация полной истории чата с сохранением в файл.
 *
 * Вся история диалога хранится целиком в JSON‑файле, а окно для LLM формируется
 * на лету на основе полной истории и ограничений контекстного окна.
 *
 * Подходит для случаев когда:
 * - нужно сохранять диалоги между перезапусками приложения;
 * - требуется аудит, отладка или аналитика по всей истории общения;
 * - LLM должна видеть только ограниченное окно, но бэкенд — все сообщения.
 *
 * Файл истории определяется сочетанием:
 * - каталога хранения `$directory`;
 * - ключа сессии `$key`;
 * - префикса `$prefix` и расширения `$ext`.
 *
 * Пример использования:
 *
 * <code>
 * use app\modules\neuron\classes\neuron\history\FileFullChatHistory;
 *
 * $history = new FileFullChatHistory(
 *     directory: '/var/log/neuron-chats',
 *     key: $sessionId,
 *     contextWindow: 8_000,
 * );
 *
 * $history->addMessage($userMessage);
 * $history->addMessage($assistantMessage);
 *
 * // Окно для LLM
 * $messagesForLlm = $history->getMessages();
 *
 * // Вся история диалога (читается из файла при создании объекта)
 * $fullHistory = $history->getFullMessages();
 * </code>
 */
final class FileFullChatHistory extends AbstractFullChatHistory
{
    public function __construct(
        protected string $directory,
        protected string $key,
        int $contextWindow = 50000,
        protected string $prefix = 'neuron_',
        protected string $ext = '.chat',
        ?HistoryTrimmerInterface $trimmer = null
    ) {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true)) {
            throw new ChatHistoryException(
                "Directory '{$this->directory}' does not exist and could not be created."
            );
        }

        parent::__construct($contextWindow, $trimmer);
    }

    /**
     * Загружает полную историю сообщений из файла.
     */
    protected function loadFullHistory(): void
    {
        if (!is_file($this->getFilePath())) {
            $this->fullHistory = [];

            return;
        }

        $raw = file_get_contents($this->getFilePath());
        if ($raw === false) {
            $this->fullHistory = [];

            return;
        }

        $decoded = json_decode($raw, true) ?? [];
        $this->fullHistory = $this->deserializeMessages($decoded);
    }

    /**
     * Сохраняет полную историю сообщений в файл.
     *
     * @throws ChatHistoryException
     */
    protected function persistFullHistory(): void
    {
        $content = json_encode($this->jsonSerialize());
        $filePath = $this->getFilePath();

        $result = @file_put_contents($filePath, $content, LOCK_EX);

        if ($result === false) {
            $result = file_put_contents($filePath, $content);
        }

        if ($result === false) {
            throw new ChatHistoryException("Unable to save the chat history to file '{$filePath}'");
        }
    }

    /**
     * Удаляет файл истории и очищает внутренние структуры.
     *
     * @throws ChatHistoryException
     */
    protected function clear(): void
    {
        $filePath = $this->getFilePath();

        if (file_exists($filePath) && !unlink($filePath)) {
            throw new ChatHistoryException("Unable to delete the file '{$filePath}'");
        }
    }

    /**
     * Возвращает путь к файлу истории.
     */
    protected function getFilePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->prefix . $this->key . $this->ext;
    }
}
