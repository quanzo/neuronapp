<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;

use function array_slice;
use function count;
use function max;

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
     * @param ChatHistoryInterface $from        Исходная история, из которой читаются сообщения.
     * @param ChatHistoryInterface $to          Целевая история, в которую добавляются сообщения.
     * @param int                  $excludeLast Сколько последних сообщений не копировать (0 — все).
     *
     * @return void
     */
    public static function copy(ChatHistoryInterface $from, ChatHistoryInterface $to, int $excludeLast = 0): void
    {
        $messages = ChatHistoryEditHelper::getMessages($from);
        $limit    = count($messages) - max(0, $excludeLast);

        /** @var Message $message */
        foreach (array_slice($messages, 0, max(0, $limit)) as $message) {
            $to->addMessage(clone $message);
        }
    }
}
