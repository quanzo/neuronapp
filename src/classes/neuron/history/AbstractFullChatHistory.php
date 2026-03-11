<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\history;

use app\modules\neuron\classes\neuron\trimmers\FluidContextWindowTrimmer;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ChatHistoryException;

use function count;

/**
 * Базовый класс истории чата с разделением полной истории и окна для LLM.
 *
 * В отличие от стандартных реализаций истории NeuronAI, которые фактически
 * хранят только обрезанное окно, этот класс поддерживает две проекции истории:
 * - полная история (`fullHistory`) — все когда‑либо добавленные сообщения;
 * - окно (`history`, наследуемое из {@see AbstractChatHistory}) — подмассив
 *   сообщений, укладывающийся в контекстное окно модели и предназначенный
 *   непосредственно для отправки в LLM.
 *
 * Полная история никогда не обрезается логически, а ограничение по размеру
 * применяется только к окну через {@see HistoryTrimmerInterface}. Это позволяет:
 * - хранить полный лог сессии для аудита, отладки и аналитики;
 * - независимо управлять размером окна для конкретной LLM‑модели;
 * - переключаться между разными триммерами без потери исторических данных.
 *
 * По умолчанию в качестве триммера используется {@see FluidContextWindowTrimmer},
 * который строит окно по токенам и поддерживает «прилипающий» к хвосту режим,
 * но вы можете передать любой другой {@see HistoryTrimmerInterface}.
 *
 * Типичные сценарии использования:
 * - in‑memory история для временных или тестовых сессий
 *   ({@see InMemoryFullChatHistory});
 * - файловая история для долговременного хранения диалогов
 *   ({@see FileFullChatHistory}).
 *
 * Пример (общая идея работы с наследниками):
 *
 * <code>
 * use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
 * use app\modules\neuron\classes\neuron\trimmers\FluidContextWindowTrimmer;
 *
 * $history = new InMemoryFullChatHistory(
 *     contextWindow: 8_000,
 *     trimmer: new FluidContextWindowTrimmer(),
 * );
 *
 * // Добавление сообщений
 * $history->addMessage($userMessage);
 * $history->addMessage($assistantMessage);
 *
 * // Окно для LLM (ограничено по токенам)
 * $messagesForLlm = $history->getMessages();
 *
 * // Полная история всей сессии
 * $fullLog = $history->getFullMessages();
 * </code>
 */
abstract class AbstractFullChatHistory extends AbstractChatHistory
{
    /**
     * Полная история сообщений диалога.
     *
     * @var Message[]
     */
    protected array $fullHistory = [];

    /**
     * @param int                        $contextWindow Максимальный размер контекста для LLM в токенах.
     * @param HistoryTrimmerInterface|null $trimmer   Триммер окна; по умолчанию используется FluidContextWindowTrimmer.
     */
    public function __construct(
        int $contextWindow = 50000,
        ?HistoryTrimmerInterface $trimmer = null
    ) {
        parent::__construct(
            $contextWindow,
            $trimmer ?? new FluidContextWindowTrimmer()
        );

        $this->loadFullHistory();
        $this->rebuildWindow();
    }

    /**
     * Добавляет новое сообщение в полную историю и пересчитывает окно для LLM.
     *
     * @return $this
     */
    public function addMessage(Message $message): \NeuronAI\Chat\History\ChatHistoryInterface
    {
        $this->fullHistory[] = $message;

        $this->rebuildWindow();

        $this->onNewMessage($message);
        $this->persistFullHistory();

        return $this;
    }

    /**
     * Возвращает сообщения окна для LLM.
     *
     * @return Message[]
     */
    public function getMessages(): array
    {
        return $this->history;
    }

    /**
     * Возвращает последнее сообщение в текущем окне истории.
     *
     * @throws ChatHistoryException
     */
    public function getLastMessage(): Message
    {
        if ($this->history === []) {
            throw new ChatHistoryException(
                'No messages in the chat window. It may have been filled with too large single message.'
            );
        }

        return $this->history[count($this->history) - 1];
    }

    /**
     * Очищает полную историю и окно.
     *
     * @return $this
     */
    public function flushAll(): \NeuronAI\Chat\History\ChatHistoryInterface
    {
        $this->clear();
        $this->fullHistory = [];
        $this->history = [];

        return $this;
    }

    /**
     * Возвращает общее количество токенов в текущем окне для LLM.
     */
    public function calculateTotalUsage(): int
    {
        return $this->trimmer->getTotalTokens();
    }

    /**
     * Сериализует полную историю для хранения.
     *
     * @return array<int, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->getFullMessages();
    }

    /**
     * Возвращает полную историю сообщений.
     *
     * @return Message[]
     */
    public function getFullMessages(): array
    {
        return $this->fullHistory;
    }

    /**
     * Возвращает последнее сообщение полной истории.
     *
     * @throws ChatHistoryException
     */
    public function getFullLastMessage(): Message
    {
        if ($this->fullHistory === []) {
            throw new ChatHistoryException('No messages in the full chat history.');
        }

        return $this->fullHistory[count($this->fullHistory) - 1];
    }

    /**
     * Пересчитывает окно для LLM на основе полной истории и текущего контекстного окна.
     */
    protected function rebuildWindow(): void
    {
        if ($this->fullHistory === []) {
            $this->history = [];

            return;
        }

        $this->history = $this->trimmer->trim($this->fullHistory, $this->contextWindow);
    }

    /**
     * Загружает полную историю из конкретного хранилища.
     *
     * Конкретные реализации (in-memory, file, БД и т.д.) определяют, откуда
     * берутся сообщения и в каком виде они сохраняются.
     */
    abstract protected function loadFullHistory(): void;

    /**
     * Сохраняет полную историю в конкретное хранилище.
     */
    abstract protected function persistFullHistory(): void;
}
