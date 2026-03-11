<?php

declare(strict_types=1);

namespace Tests\Neuron;

use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see InMemoryFullChatHistory}.
 */
final class FullChatHistoryInMemoryTest extends TestCase
{
    public function testFullHistoryKeepsAllMessagesWhileWindowIsLimited(): void
    {
        $history = new InMemoryFullChatHistory(contextWindow: 50);

        for ($i = 0; $i < 20; $i++) {
            $history->addMessage(new Message(MessageRole::USER, "Question {$i}"));
            $history->addMessage(new Message(MessageRole::ASSISTANT, "Answer {$i}"));
        }

        $full = $history->getFullMessages();
        $window = $history->getMessages();

        $this->assertCount(40, $full);
        $this->assertNotEmpty($window);
        $this->assertLessThanOrEqual(40, count($window));
        $this->assertLessThan(count($full), count($window));
    }

    public function testWindowContainsTailOfConversation(): void
    {
        $history = new InMemoryFullChatHistory(contextWindow: 50);

        for ($i = 0; $i < 10; $i++) {
            $history->addMessage(new Message(MessageRole::USER, "Question {$i}"));
            $history->addMessage(new Message(MessageRole::ASSISTANT, "Answer {$i}"));
        }

        $window = $history->getMessages();
        $this->assertNotEmpty($window);

        $last = $window[count($window) - 1];
        $this->assertSame('Answer 9', $last->getContent());
    }
}
