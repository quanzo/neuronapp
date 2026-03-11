<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\history;

/**
 * Реализация полной истории чата, хранящейся только в памяти процесса.
 *
 * Полная история никогда не записывается на диск и живёт ровно столько,
 * сколько живёт объект. Окно для LLM формируется на основе полной истории
 * через триммер, заданный в базовом {@see AbstractFullChatHistory}.
 *
 * Используйте этот класс когда:
 * - важна скорость и простота (нет дискового I/O);
 * - история нужна только в рамках текущего процесса (без восстановления
 *   после перезапуска);
 * - вы хотите иметь полный лог диалога для отладки или UI, но отправлять
 *   в модель только нарезанное по токенам окно.
 *
 * Пример использования:
 *
 * <code>
 * use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
 *
 * $history = new InMemoryFullChatHistory(contextWindow: 4_000);
 *
 * $history
 *     ->addMessage($userMessage)
 *     ->addMessage($assistantMessage);
 *
 * // Сообщения, которые пойдут в LLM
 * $messagesForLlm = $history->getMessages();
 *
 * // Вся история диалога
 * $fullHistory = $history->getFullMessages();
 * </code>
 */
final class InMemoryFullChatHistory extends AbstractFullChatHistory
{
    /**
     * Загружает полную историю из хранилища.
     *
     * Для in-memory реализации история всегда начинается с пустого массива.
     */
    protected function loadFullHistory(): void
    {
        $this->fullHistory = [];
    }

    /**
     * Сохраняет полную историю в хранилище.
     *
     * Для in-memory реализации метод ничего не делает, так как состояние
     * живёт только в памяти процесса.
     */
    protected function persistFullHistory(): void
    {
        // no-op
    }
}
