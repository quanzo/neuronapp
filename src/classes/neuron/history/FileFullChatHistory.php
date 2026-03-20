<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\history;

use app\modules\neuron\helpers\ChatHistoryToolMessageHelper;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function array_values;
use function count;
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
        $content = json_encode($this->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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

    /**
     * Очищает полную историю от вызовов/ответов инструментов просмотра истории.
     *
     * Нужен для предотвращения разрастания истории копиями: LLM может вызывать
     * chat_history.* и получать большие ответы, но эти ответы не должны навсегда
     * оставаться в контексте и вытеснять полезный диалог.
     *
     * Метод удаляет:
     * - tool-call сообщения указанных инструментов;
     * - tool-result сообщения, следующие сразу после соответствующего tool-call;
     * - tool-result сообщения, которые сами идентифицируются как ответы указанных инструментов.
     *
     * После очистки пересобирается окно и сохраняется файл истории.
     *
     * @param list<string>|null $toolNames Полные имена инструментов. Если null — используются chat_history.size/meta/message.
     *
     * @return int Количество удалённых сообщений.
     */
    public function purgeHistoryInspectionTools(?array $toolNames = null): int
    {
        $toolNames = $toolNames ?? [
            'chat_history.size',
            'chat_history.meta',
            'chat_history.message',
        ];

        if ($this->fullHistory === []) {
            return 0;
        }

        $removed = 0;
        $kept = [];
        $count = count($this->fullHistory);

        for ($i = 0; $i < $count; $i++) {
            $msg = $this->fullHistory[$i];

            // Удаляем tool-call + его tool-result (если это наши history-tools).
            if ($msg instanceof ToolCallMessage && ChatHistoryToolMessageHelper::isToolMessageInList($msg, $toolNames)) {
                $removed++;

                if ($i + 1 < $count && $this->fullHistory[$i + 1] instanceof ToolResultMessage) {
                    $removed++;
                    $i++; // пропускаем tool-result
                }

                continue;
            }

            // Подстраховка: если tool-result сам распознан как относящийся к history-tools — удаляем.
            if ($msg instanceof ToolResultMessage && ChatHistoryToolMessageHelper::isToolMessageInList($msg, $toolNames)) {
                $removed++;
                continue;
            }

            $kept[] = $msg;
        }

        if ($removed === 0) {
            return 0;
        }

        $this->fullHistory = array_values($kept);
        $this->rebuildWindow();
        $this->persistFullHistory();

        return $removed;
    }
}
