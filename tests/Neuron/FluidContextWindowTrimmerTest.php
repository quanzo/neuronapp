<?php

declare(strict_types=1);

namespace Tests\Neuron;

use app\modules\neuron\classes\neuron\trimmers\FluidContextWindowTrimmer;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see FluidContextWindowTrimmer}.
 */
final class FluidContextWindowTrimmerTest extends TestCase
{
    public function testEmptyHistoryReturnsEmpty(): void
    {
        $trimmer = new FluidContextWindowTrimmer(new TokenCounter());
        $result = $trimmer->trim([], 1000);

        $this->assertSame([], $result);
        $this->assertSame(0, $trimmer->getTotalTokens());
    }

    public function testDefaultAnchorUsesTailWindow(): void
    {
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new Message(MessageRole::USER, "Question {$i}");
            $messages[] = new Message(MessageRole::ASSISTANT, "Answer {$i}");
        }

        $trimmer = new FluidContextWindowTrimmer(new TokenCounter());
        $result = $trimmer->trim($messages, 120);

        $this->assertNotEmpty($result);

        $last = $result[count($result) - 1];
        $this->assertSame('Answer 9', $last->getContent());
    }

    public function testTailModeOverridesManualAnchor(): void
    {
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new Message(MessageRole::USER, "Question {$i}");
            $messages[] = new Message(MessageRole::ASSISTANT, "Answer {$i}");
        }

        // Устанавливаем якорь в начало истории, но затем явно включаем режим хвоста.
        $trimmer = (new FluidContextWindowTrimmer(new TokenCounter()))
            ->withAnchorIndex(0)
            ->withTailMode(true);

        $result = $trimmer->trim($messages, 120);

        $this->assertNotEmpty($result);
        $last = $result[count($result) - 1];
        $this->assertSame('Answer 9', $last->getContent());
    }

    public function testManualAnchorMovesWindowBackwards(): void
    {
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new Message(MessageRole::USER, "Question {$i}");
            $messages[] = new Message(MessageRole::ASSISTANT, "Answer {$i}");
        }

        $anchorIndex = 9 * 2; // пара Question 9 / Answer 9 начинается с индекса 18

        $trimmer = (new FluidContextWindowTrimmer(new TokenCounter()))
            ->withAnchorIndex($anchorIndex);

        $result = $trimmer->trim($messages, 80);

        $this->assertNotEmpty($result);
        $contents = array_map(
            static fn (Message $message): ?string => $message->getContent(),
            $result
        );

        $this->assertContains('Question 7', $contents);
        $this->assertContains('Answer 8', $contents);
        $this->assertNotContains('Question 0', $contents);
    }

    public function testToolCallAndResultNotSplitAroundAnchor(): void
    {
        $messages = [];
        $messages[] = new Message(MessageRole::USER, 'Start');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Before tools');

        $toolCall = new ToolCallMessage(null, []);
        $toolResult = new ToolResultMessage([]);
        $messages[] = $toolCall;
        $messages[] = $toolResult;

        $messages[] = new Message(MessageRole::USER, 'After tool');
        $messages[] = new Message(MessageRole::ASSISTANT, 'After tool answer');

        $anchorIndex = 3;

        $trimmer = (new FluidContextWindowTrimmer(new TokenCounter()))
            ->withAnchorIndex($anchorIndex);

        $result = $trimmer->trim($messages, 40);

        $this->assertNotEmpty($result);

        $hasPair = false;
        for ($i = 0; $i < count($result) - 1; $i++) {
            if ($result[$i] instanceof ToolCallMessage && $result[$i + 1] instanceof ToolResultMessage) {
                $hasPair = true;
                break;
            }
        }

        $this->assertTrue($hasPair, 'ToolCall/ToolResult пара должна сохраняться внутри окна без разрыва');
    }

    public function testTotalTokensWithinContextWindowOrSlightlyAboveForToolPairs(): void
    {
        $messages = [];
        $messages[] = new Message(MessageRole::USER, 'Short');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Short answer');

        $toolCall = new ToolCallMessage(null, []);
        $toolResult = new ToolResultMessage([]);
        $messages[] = $toolCall;
        $messages[] = $toolResult;

        $messages[] = new Message(MessageRole::USER, 'Another question');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Another answer');

        $contextWindow = 20;

        $trimmer = (new FluidContextWindowTrimmer(new TokenCounter()))
            ->withAnchorIndex(3);

        $result = $trimmer->trim($messages, $contextWindow);

        $this->assertNotEmpty($result);

        $tokens = $trimmer->getTotalTokens();
        $this->assertGreaterThan(0, $tokens);
        $this->assertLessThanOrEqual($contextWindow * 2, $tokens);
    }
}
