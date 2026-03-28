<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use NeuronAI\Chat\History\ChatHistoryInterface;

use function array_slice;
use function array_splice;
use function count;

/**
 * Хелпер для получения количества сообщений в истории чата и отката (truncate) до заданного числа.
 *
 * Используется при resume по чекпоинту: откат истории до history_message_count сообщений
 * перед продолжением выполнения todo, чтобы сохранить порядок рассуждения без дубликатов.
 *
 * Откат выполняется через рефлексию (установка защищённого свойства history и вызов setMessages),
 * так как ChatHistoryInterface не предоставляет публичного API замены всей истории.
 */
final class ChatHistoryTruncateHelper
{
    /**
     * Возвращает количество сообщений в истории чата.
     *
     * @param ChatHistoryInterface $history История чата (например, из ConfigurationAgent::getChatHistory()).
     * @return int Число сообщений (0 для пустой истории).
     */
    public static function getMessageCount(ChatHistoryInterface $history): int
    {
        return count($history->getMessages());
    }

    /**
     * Обрезает историю чата до первых $count сообщений и сохраняет изменения.
     *
     * Если в истории уже не больше $count сообщений, ничего не делает.
     * Иначе устанавливает внутренний массив сообщений в array_slice(messages, 0, count)
     * и вызывает защищённый метод setMessages() реализации (через рефлексию), чтобы
     * FileChatHistory и другие реализации сохранили состояние на диск.
     *
     * @param ChatHistoryInterface $history История чата (должна быть экземпляром AbstractChatHistory или наследника).
     * @param int                  $count   Оставляемое количество сообщений (0 — очистить).
     * @throws \ReflectionException Если не удаётся получить свойство или метод.
     * @throws \RuntimeException    Если объект не поддерживает откат (нет свойства history / setMessages).
     */
    public static function truncateToMessageCount(ChatHistoryInterface $history, int $count): void
    {
        $messages = $history->getMessages();
        if (count($messages) <= $count) {
            return;
        }

        $truncated = $count > 0 ? array_slice($messages, 0, $count) : [];

        $ref = new \ReflectionClass($history);
        if (!$ref->hasProperty('history')) {
            throw new \RuntimeException('История чата не поддерживает откат: отсутствует свойство history.');
        }
        $propHistory = $ref->getProperty('history');
        $propHistory->setAccessible(true);
        $propHistory->setValue($history, $truncated);

        if (!$ref->hasMethod('setMessages')) {
            throw new \RuntimeException('История чата не поддерживает откат: отсутствует метод setMessages.');
        }
        $methodSet = $ref->getMethod('setMessages');
        $methodSet->setAccessible(true);
        $methodSet->invoke($history, $truncated);
    }

    /**
     * Удаляет одно сообщение по индексу в текущем массиве истории (окно InMemoryChatHistory).
     *
     * @param ChatHistoryInterface $history Реализация с полем history и методом setMessages.
     * @param int $index Индекс удаляемого сообщения (0..count-1).
     * @throws \InvalidArgumentException Если индекс вне диапазона.
     * @throws \ReflectionException Если рефлексия недоступна.
     * @throws \RuntimeException Если реализация не поддерживает правку.
     */
    public static function deleteMessageAtIndex(ChatHistoryInterface $history, int $index): void
    {
        $messages = $history->getMessages();
        $count = count($messages);
        if ($index < 0 || $index >= $count) {
            throw new \InvalidArgumentException('Некорректный индекс сообщения для удаления: ' . $index);
        }

        $newMessages = $messages;
        array_splice($newMessages, $index, 1);

        $ref = new \ReflectionClass($history);
        if (!$ref->hasProperty('history')) {
            throw new \RuntimeException('История чата не поддерживает удаление: отсутствует свойство history.');
        }
        $propHistory = $ref->getProperty('history');
        $propHistory->setAccessible(true);
        $propHistory->setValue($history, $newMessages);

        if (!$ref->hasMethod('setMessages')) {
            throw new \RuntimeException('История чата не поддерживает удаление: отсутствует метод setMessages.');
        }
        $methodSet = $ref->getMethod('setMessages');
        $methodSet->setAccessible(true);
        $methodSet->invoke($history, $newMessages);
    }
}
