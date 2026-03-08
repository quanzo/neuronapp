<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\ChatHistoryTruncateHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see ChatHistoryTruncateHelper}.
 *
 * ChatHistoryTruncateHelper — получение количества сообщений и откат истории
 * до заданного числа сообщений (для resume по чекпоинту).
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\ChatHistoryTruncateHelper}
 */
class ChatHistoryTruncateHelperTest extends TestCase
{
    /**
     * getMessageCount() для пустой истории возвращает 0.
     */
    public function testGetMessageCountEmpty(): void
    {
        $history = new InMemoryChatHistory();
        $this->assertSame(0, ChatHistoryTruncateHelper::getMessageCount($history));
    }

    /**
     * getMessageCount() после добавления одного сообщения возвращает 1.
     */
    public function testGetMessageCountAfterAdd(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('one'));
        $this->assertSame(1, ChatHistoryTruncateHelper::getMessageCount($history));
    }

    /**
     * truncateToMessageCount() обрезает историю до заданного числа; getMessageCount отражает изменение.
     * Тест: одна запись, обрезка до 0.
     */
    public function testTruncateToMessageCount(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('msg'));
        $this->assertSame(1, ChatHistoryTruncateHelper::getMessageCount($history));

        ChatHistoryTruncateHelper::truncateToMessageCount($history, 0);
        $this->assertSame(0, ChatHistoryTruncateHelper::getMessageCount($history));

        $messages = $history->getMessages();
        $this->assertCount(0, $messages);
    }

    /**
     * truncateToMessageCount() с count >= текущего размера ничего не делает.
     */
    public function testTruncateNoOpWhenCountNotLess(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('a'));
        ChatHistoryTruncateHelper::truncateToMessageCount($history, 5);
        $this->assertSame(1, ChatHistoryTruncateHelper::getMessageCount($history));
    }

    /**
     * truncateToMessageCount() с count = 0 очищает историю.
     */
    public function testTruncateToZero(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('a'));
        ChatHistoryTruncateHelper::truncateToMessageCount($history, 0);
        $this->assertSame(0, ChatHistoryTruncateHelper::getMessageCount($history));
    }
}
