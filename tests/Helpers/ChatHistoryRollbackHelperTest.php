<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
use app\modules\neuron\helpers\ChatHistoryRollbackHelper;
use InvalidArgumentException;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see ChatHistoryRollbackHelper}.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\ChatHistoryRollbackHelper}
 */
final class ChatHistoryRollbackHelperTest extends TestCase
{
    /**
     * Пустая InMemoryChatHistory: снимок размера равен 0.
     */
    public function testGetSnapshotCountInMemoryEmpty(): void
    {
        $history = new InMemoryChatHistory();
        $this->assertSame(0, ChatHistoryRollbackHelper::getSnapshotCount($history));
    }

    /**
     * InMemoryChatHistory с тремя сообщениями: снимок совпадает с числом сообщений в окне.
     */
    public function testGetSnapshotCountInMemoryNonEmpty(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('a'));
        $history->addMessage(new AssistantMessage('b'));
        $history->addMessage(new UserMessage('c'));
        $this->assertSame(3, ChatHistoryRollbackHelper::getSnapshotCount($history));
    }

    /**
     * Пустая InMemoryFullChatHistory: снимок по полной истории — 0.
     */
    public function testGetSnapshotCountFullEmpty(): void
    {
        $history = new InMemoryFullChatHistory();
        $this->assertSame(0, ChatHistoryRollbackHelper::getSnapshotCount($history));
    }

    /**
     * InMemoryFullChatHistory: снимок считает полную историю, а не только окно (два user + assistant).
     */
    public function testGetSnapshotCountFullMatchesFullMessages(): void
    {
        $history = new InMemoryFullChatHistory();
        $history->addMessage(new UserMessage('u1'));
        $history->addMessage(new AssistantMessage('a1'));
        $history->addMessage(new UserMessage('u2'));
        $this->assertSame(3, ChatHistoryRollbackHelper::getSnapshotCount($history));
        $this->assertCount(3, $history->getFullMessages());
    }

    /**
     * Обычная история: откат с трёх сообщений к двум — удаляется только последнее.
     * Три подряд UserMessage без Assistant NeuronAI‑триммер может сжать до одного (ensureValidMessageSequence);
     * цепочка user → assistant → user сохраняет три записи в окне.
     */
    public function testRollbackSimpleFromThreeToTwo(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('keep1'));
        $history->addMessage(new AssistantMessage('keep2'));
        $history->addMessage(new UserMessage('drop'));
        ChatHistoryRollbackHelper::rollbackToSnapshot($history, 2);
        $this->assertSame(2, ChatHistoryRollbackHelper::getSnapshotCount($history));
        $this->assertStringContainsString('keep2', (string) $history->getLastMessage()->getContent());
    }

    /**
     * Обычная история: откат к 0 — полная очистка окна.
     */
    public function testRollbackSimpleToZero(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('only'));
        ChatHistoryRollbackHelper::rollbackToSnapshot($history, 0);
        $this->assertSame(0, ChatHistoryRollbackHelper::getSnapshotCount($history));
    }

    /**
     * Полная история: с четырёх сообщений откат к двум — с хвоста убираются два элемента fullHistory.
     */
    public function testRollbackFullRemovesTwoFromTail(): void
    {
        $history = new InMemoryFullChatHistory();
        $history->addMessage(new UserMessage('u1'));
        $history->addMessage(new AssistantMessage('a1'));
        $history->addMessage(new UserMessage('u2'));
        $history->addMessage(new AssistantMessage('a2'));
        ChatHistoryRollbackHelper::rollbackToSnapshot($history, 2);
        $this->assertCount(2, $history->getFullMessages());
        $this->assertSame('u1', (string) $history->getFullMessages()[0]->getContent());
        $this->assertSame('a1', (string) $history->getFullMessages()[1]->getContent());
    }

    /**
     * Полная история: одно сообщение, откат к 0 — пустая полная история и согласованное окно.
     */
    public function testRollbackFullSingleMessageToZero(): void
    {
        $history = new InMemoryFullChatHistory();
        $history->addMessage(new UserMessage('solo'));
        ChatHistoryRollbackHelper::rollbackToSnapshot($history, 0);
        $this->assertSame(0, ChatHistoryRollbackHelper::getSnapshotCount($history));
        $this->assertSame([], $history->getFullMessages());
    }

    /**
     * Откат к текущему размеру (no-op): лишних удалений нет.
     */
    public function testRollbackNoOpWhenCountEqualsCurrent(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('x'));
        $before = ChatHistoryRollbackHelper::getSnapshotCount($history);
        ChatHistoryRollbackHelper::rollbackToSnapshot($history, $before);
        $this->assertSame(1, ChatHistoryRollbackHelper::getSnapshotCount($history));
    }

    /**
     * Заведомо «больший» снимок, чем сообщений: для простой истории truncate не трогает (count <= countBefore).
     */
    public function testRollbackSimpleNoOpWhenCountBeforeGreaterThanCurrent(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('one'));
        ChatHistoryRollbackHelper::rollbackToSnapshot($history, 99);
        $this->assertSame(1, ChatHistoryRollbackHelper::getSnapshotCount($history));
    }

    /**
     * Отрицательный countBefore для простой истории — InvalidArgumentException.
     */
    public function testRollbackThrowsOnNegativeCountBeforeSimple(): void
    {
        $history = new InMemoryChatHistory();
        $this->expectException(InvalidArgumentException::class);
        ChatHistoryRollbackHelper::rollbackToSnapshot($history, -1);
    }

    /**
     * Отрицательный countBefore для полной истории — InvalidArgumentException.
     */
    public function testRollbackThrowsOnNegativeCountBeforeFull(): void
    {
        $history = new InMemoryFullChatHistory();
        $history->addMessage(new UserMessage('x'));
        $this->expectException(InvalidArgumentException::class);
        ChatHistoryRollbackHelper::rollbackToSnapshot($history, -5);
    }
}
