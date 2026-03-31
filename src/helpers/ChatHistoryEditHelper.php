<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;

use function array_slice;
use function array_splice;
use function array_values;
use function count;

/**
 * Хелпер для редактирования полной истории сообщений {@see AbstractFullChatHistory}.
 *
 * Нужен для сценариев управления сессиями (удаление/вставка сообщений по индексу),
 * так как публичного API изменения `fullHistory` в NeuronAI-историях нет.
 *
 * Хелпер:
 * - изменяет защищённое поле `fullHistory` через Reflection;
 * - пересобирает окно сообщений для LLM (`rebuildWindow`);
 * - сохраняет изменения в хранилище (`persistFullHistory`) для файловых реализаций.
 *
 * Пример использования:
 *
 * <code>
 * $history = new FileFullChatHistory($dir, $sessionKey);
 * ChatHistoryEditHelper::deleteFullMessageAt($history, 0);
 * ChatHistoryEditHelper::insertFullMessageAt($history, 1, $message);
 * </code>
 */
final class ChatHistoryEditHelper
{
    /**
     * Возвращает количество сообщений в полной истории.
     *
     * @param AbstractFullChatHistory $history История с полной проекцией.
     */
    public static function getFullMessageCount(AbstractFullChatHistory $history): int
    {
        return count($history->getFullMessages());
    }

    /**
     * Удаляет сообщение из полной истории по индексу.
     *
     * @param AbstractFullChatHistory $history История с полной проекцией.
     * @param int $index Индекс в диапазоне 0..(count-1).
     */
    public static function deleteFullMessageAt(AbstractFullChatHistory $history, int $index): void
    {
        $messages = $history->getFullMessages();

        if ($index < 0 || $index >= count($messages)) {
            throw new \InvalidArgumentException('Некорректный индекс сообщения для удаления: ' . $index);
        }

        array_splice($messages, $index, 1);

        self::replaceFullHistory($history, $messages);
    }

    /**
     * Удаляет последние $count сообщений из полной истории (с конца).
     *
     * @param AbstractFullChatHistory $history История с полной проекцией.
     * @param int $count Сколько сообщений снять с хвоста (0 — ничего не делать).
     */
    public static function deleteLastFullMessages(AbstractFullChatHistory $history, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $n = count($history->getFullMessages());
            if ($n === 0) {
                return;
            }

            self::deleteFullMessageAt($history, $n - 1);
        }
    }

    /**
     * Вставляет сообщение в полную историю по индексу.
     *
     * Вставка допускает индекс в диапазоне 0..count (включая вставку в конец).
     *
     * @param AbstractFullChatHistory $history История с полной проекцией.
     * @param int $index Индекс вставки (0..count).
     * @param Message $message Сообщение для вставки.
     */
    public static function insertFullMessageAt(AbstractFullChatHistory $history, int $index, Message $message): void
    {
        $messages = $history->getFullMessages();
        $count = count($messages);

        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException('Некорректный индекс сообщения для вставки: ' . $index);
        }

        array_splice($messages, $index, 0, [$message]);

        self::replaceFullHistory($history, $messages);
    }

    /**
     * Делает копию сообщений истории в диапазоне снимков [before..after).
     *
     * Назначение: получить «дельту» сообщений, добавленных между двумя измерениями размера истории.
     *
     * Важно:
     * - для {@see AbstractFullChatHistory} диапазон считается по полной истории (`getFullMessages()`),
     *   для остальных реализаций — по окну (`getMessages()`).
     *
     * @param ChatHistoryInterface $history История агента.
     * @param int $countBefore Количество сообщений в истории «до».
     * @param int $countAfter Количество сообщений в истории «после».
     *
     * @return array<int, Message>
     */
    public static function copyMessagesBySnapshotRange(
        ChatHistoryInterface $history,
        int $countBefore,
        int $countAfter
    ): array {
        if ($countBefore < 0 || $countAfter < 0) {
            return [];
        }
        if ($countAfter <= $countBefore) {
            return [];
        }

        $messages = $history instanceof AbstractFullChatHistory
            ? $history->getFullMessages()
            : $history->getMessages();

        return array_slice($messages, $countBefore, $countAfter - $countBefore);
    }

    /**
     * Заменяет сообщения в диапазоне снимков [before..after) одним сообщением.
     *
     * Назначение: схлопнуть «дельту» сообщений (например, одного шага внешнего цикла) в summary,
     * не затрагивая остальную историю.
     *
     * Важно:
     * - для {@see AbstractFullChatHistory} редактируется полная история (`fullHistory`) через публичные методы этого хелпера;
     * - для остальных реализаций считается, что «вся история = окно» и используется обрезка до `$countBefore`,
     *   затем добавление одного сообщения.
     *
     * @param ChatHistoryInterface $history История агента.
     * @param int $countBefore Количество сообщений в истории «до».
     * @param int $countAfter Количество сообщений в истории «после».
     * @param Message $replacement Сообщение, которым заменяем диапазон.
     */
    public static function replaceMessagesBySnapshotRange(
        ChatHistoryInterface $history,
        int $countBefore,
        int $countAfter,
        Message $replacement
    ): void {
        if ($countBefore < 0 || $countAfter < 0) {
            return;
        }
        if ($countAfter < $countBefore) {
            return;
        }

        $delta = $countAfter - $countBefore;

        if ($history instanceof AbstractFullChatHistory) {
            if ($delta <= 0) {
                return;
            }

            $full = $history->getFullMessages();
            array_splice($full, $countBefore, $delta, [$replacement]);
            $full = array_values($full);
            self::replaceFullHistory($history, $full);

            return;
        }

        // Для обычной истории считаем, что можно заменить только хвост (дельта всегда на хвосте).
        // Поэтому сначала обрезаем к состоянию "до", затем добавляем одно сообщение.
        ChatHistoryTruncateHelper::truncateToMessageCount($history, $countBefore);
        $history->addMessage($replacement);
    }

    /**
     * Заменяет полную историю на переданный массив и синхронизирует производные структуры.
     *
     * @param AbstractFullChatHistory $history История.
     * @param Message[] $fullMessages Новая полная история.
     */
    private static function replaceFullHistory(AbstractFullChatHistory $history, array $fullMessages): void
    {
        $ref = new \ReflectionClass($history);

        if (!$ref->hasProperty('fullHistory')) {
            throw new \RuntimeException(
                'История чата не поддерживает редактирование: отсутствует свойство fullHistory.'
            );
        }

        $propFull = $ref->getProperty('fullHistory');
        $propFull->setAccessible(true);
        $propFull->setValue($history, $fullMessages);

        if ($ref->hasMethod('rebuildWindow')) {
            $m = $ref->getMethod('rebuildWindow');
            $m->setAccessible(true);
            $m->invoke($history);
        }

        if ($ref->hasMethod('persistFullHistory')) {
            $m = $ref->getMethod('persistFullHistory');
            $m->setAccessible(true);
            $m->invoke($history);
        }
    }
}
