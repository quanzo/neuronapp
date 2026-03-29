<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use InvalidArgumentException;
use NeuronAI\Chat\History\ChatHistoryInterface;

use function max;

/**
 * Снимок размера истории чата и откат к этому размеру после неудачной попытки вызова LLM.
 *
 * NeuronAI добавляет пользовательское сообщение в историю до вызова провайдера; при повторном
 * `chat()` / `structured()` без отката в истории остаётся дубликат. Перед попыткой запоминают
 * {@see getSnapshotCount()}, после перехваченной ошибки (например, в `WaitSuccess::$funcAfterError`)
 * вызывают {@see rollbackToSnapshot()}.
 *
 * Для {@see AbstractFullChatHistory} счётчик и откат ведутся по **полной** истории (`fullHistory`),
 * иначе по окну {@see ChatHistoryInterface::getMessages()} — см. {@see ChatHistoryEditHelper}
 * и {@see ChatHistoryTruncateHelper}.
 *
 * Пример (сигнатура колбэка ошибки как у {@see \app\modules\neuron\classes\WaitSuccess::waitSuccess}):
 *
 * <code>
 * $history = $cfg->getChatHistory();
 * $before = ChatHistoryRollbackHelper::getSnapshotCount($history);
 * WaitSuccess::waitSuccess(
 *     $callable,
 *     1000,
 *     3,
 *     function (\Throwable $e, int $execCount) use ($history, $before): void {
 *         ChatHistoryRollbackHelper::rollbackToSnapshot($history, $before);
 *     }
 * );
 * </code>
 */
final class ChatHistoryRollbackHelper
{
    /**
     * Возвращает число сообщений для снимка «до попытки»: полная история или окно LLM.
     *
     * @param ChatHistoryInterface $history Текущая история агента (тот же экземпляр, что у NeuronAI).
     *
     * @return int Неотрицательное количество сообщений.
     */
    public static function getSnapshotCount(ChatHistoryInterface $history): int
    {
        if ($history instanceof AbstractFullChatHistory) {
            return ChatHistoryEditHelper::getFullMessageCount($history);
        }

        return ChatHistoryTruncateHelper::getMessageCount($history);
    }

    /**
     * Удаляет с хвоста истории всё, что появилось после снимка `$countBefore`.
     *
     * Если реализация — {@see AbstractFullChatHistory}, снимаются последние
     * `(текущее_полное_число − $countBefore)` сообщений с конца `fullHistory`, затем
     * пересобирается окно и при необходимости пишется файл. Иначе история обрезается через
     * {@see ChatHistoryTruncateHelper::truncateToMessageCount()} (вся хранимая лента = окно).
     *
     * Граничные случаи: при `$countBefore` ≥ текущего размера ничего не делается; при
     * отрицательном `$countBefore` выбрасывается {@see InvalidArgumentException}.
     *
     * @param ChatHistoryInterface $history История чата.
     * @param int                  $countBefore Целевое количество сообщений (как после {@see getSnapshotCount()} до попытки).
     *
     * @throws InvalidArgumentException Если `$countBefore` отрицателен.
     */
    public static function rollbackToSnapshot(ChatHistoryInterface $history, int $countBefore): void
    {
        if ($countBefore < 0) {
            throw new InvalidArgumentException('countBefore must be non-negative, got: ' . $countBefore);
        }

        if ($history instanceof AbstractFullChatHistory) {
            $current = ChatHistoryEditHelper::getFullMessageCount($history);
            $remove  = max(0, $current - $countBefore);
            if ($remove > 0) {
                ChatHistoryEditHelper::deleteLastFullMessages($history, $remove);
            }

            return;
        }

        ChatHistoryTruncateHelper::truncateToMessageCount($history, $countBefore);
    }
}
