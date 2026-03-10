<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;

/**
 * Хелпер для переноса истории чата между разными реализациями {@see ChatHistoryInterface}.
 *
 * Позволяет скопировать все сообщения из одного объекта истории в другой
 * через публичный API — без доступа к внутреннему состоянию конкретных реализаций.
 */
final class ChatHistoryCopyHelper
{
    /**
     * Копирует все сообщения из исходной истории в целевую.
     *
     * Сообщения переносятся в порядке их следования в истории. Типы реализаций
     * ($from и $to) могут отличаться (например, файловая история и in-memory),
     * при этом используется только публичный интерфейс {@see ChatHistoryInterface}.
     *
     * @param ChatHistoryInterface $from Исходная история, из которой читаются сообщения.
     * @param ChatHistoryInterface $to   Целевая история, в которую добавляются сообщения.
     *
     * @return void
     */
    public static function copy(ChatHistoryInterface $from, ChatHistoryInterface $to): void
    {
        /** @var Message $message */
        foreach ($from->getMessages() as $message) {
            $to->addMessage($message);
        }
    }
}
