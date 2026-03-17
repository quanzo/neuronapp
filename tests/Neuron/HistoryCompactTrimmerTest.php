<?php

declare(strict_types=1);

namespace Tests\Neuron;

use app\modules\neuron\classes\neuron\trimmers\HistoryCompactTrimmer;
use NeuronAI\Chat\Enums\MessageRole;
use app\modules\neuron\classes\neuron\trimmers\TokenCounter;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see HistoryCompactTrimmer}.
 */
final class HistoryCompactTrimmerTest extends TestCase
{
    public function testEmptyHistoryReturnsEmpty(): void
    {
        $trimmer = new HistoryCompactTrimmer(new TokenCounter(), 0.6);
        $result = $trimmer->trim([], 1000);

        $this->assertSame([], $result);
        $this->assertSame(0, $trimmer->getTotalTokens());
    }

    public function testHistorySmallerThanContextNotChanged(): void
    {
        $messages = [
            new Message(MessageRole::USER, 'Hello'),
            new Message(MessageRole::ASSISTANT, 'World'),
        ];

        $trimmer = new HistoryCompactTrimmer(new TokenCounter(), 0.6);
        $result = $trimmer->trim($messages, 10_000);

        $this->assertCount(2, $result);
        $this->assertSame('Hello', $result[0]->getContent());
        $this->assertSame('World', $result[1]->getContent());
    }

    public function testLongHistoryProducesSummaryAndTail(): void
    {
        $messages = [];
        // Формируем длинную голову
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new Message(MessageRole::USER, "Question {$i}");
            $messages[] = new Message(MessageRole::ASSISTANT, "Answer {$i}");
        }
        // Короткий хвост
        $messages[] = new Message(MessageRole::USER, 'Final question');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Final answer');

        $trimmer = new HistoryCompactTrimmer(new TokenCounter(), 0.5);
        $result = $trimmer->trim($messages, 100);

        $this->assertGreaterThan(0, $trimmer->getTotalTokens());
        $this->assertGreaterThanOrEqual(2, count($result));

        // Ожидаем, что первое сообщение — либо summary (DEVELOPER), либо пользовательское,
        // если голова оказалась пустой после эвристик.
        $firstRole = $result[0]->getRole();
        $this->assertContains($firstRole, [MessageRole::DEVELOPER->value, MessageRole::USER->value]);
        if ($firstRole === MessageRole::DEVELOPER->value) {
            $this->assertStringContainsString('Краткое резюме предыдущего диалога', $result[0]->getContent() ?? '');
        }

        // Последние сообщения хвоста должны быть сохранены.
        $last = $result[count($result) - 1];
        $this->assertSame('Final answer', $last->getContent());
    }

    public function testToolCallAndResultAreNotSplitInTail(): void
    {
        $messages = [];
        $messages[] = new Message(MessageRole::USER, 'Start');

        // Старая часть истории
        for ($i = 0; $i < 5; $i++) {
            $messages[] = new Message(MessageRole::ASSISTANT, "Old answer {$i}");
        }

        // Хвост с ToolCall/ToolResult
        $toolCall = new ToolCallMessage(null, []);
        $toolResult = new ToolResultMessage([]);
        $messages[] = $toolCall;
        $messages[] = $toolResult;
        $messages[] = new Message(MessageRole::USER, 'After tool');
        $messages[] = new Message(MessageRole::ASSISTANT, 'After tool answer');

        $trimmer = new HistoryCompactTrimmer(new TokenCounter(), 0.5);
        $result = $trimmer->trim($messages, 80);

        // Проверяем, что в результирующей истории есть и ToolCall, и ToolResult подряд.
        $hasPair = false;
        for ($i = 0; $i < count($result) - 1; $i++) {
            if ($result[$i] instanceof ToolCallMessage && $result[$i + 1] instanceof ToolResultMessage) {
                $hasPair = true;
                break;
            }
        }

        $this->assertTrue($hasPair, 'ToolCall/ToolResult пара должна сохраняться в хвосте без разрыва');
    }

    public function testSummaryDeduplicatesRepeatedHeadMessages(): void
    {
        $messages = [];

        // Голова с повторяющимися вопросами/ответами.
        for ($i = 0; $i < 3; $i++) {
            $messages[] = new Message(MessageRole::USER, 'Same question');
            $messages[] = new Message(MessageRole::ASSISTANT, 'Same answer');
        }

        // Хвост, чтобы гарантировать наличие несжатой части истории.
        $messages[] = new Message(MessageRole::USER, 'Tail question');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Tail answer');

        $trimmer = new HistoryCompactTrimmer(new TokenCounter(), 0.5);
        $result = $trimmer->trim($messages, 50);

        $this->assertNotEmpty($result);

        $first = $result[0];
        $this->assertContains($first->getRole(), [MessageRole::DEVELOPER->value, MessageRole::USER->value]);

        if ($first->getRole() === MessageRole::DEVELOPER->value) {
            $content = $first->getContent() ?? '';
            $this->assertStringContainsString('Краткое резюме предыдущего диалога', $content);

            // В summary каждая уникальная фраза должна встречаться не более одного раза.
            $this->assertSame(1, substr_count($content, 'Same question'));
            $this->assertSame(1, substr_count($content, 'Same answer'));
        }
    }

    public function testHeadContentNotDuplicatedIfPresentInTail(): void
    {
        $messages = [];

        // Голова: вопрос/ответ, которые также окажутся в хвосте.
        $messages[] = new Message(MessageRole::USER, 'Shared question');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Shared answer');

        // Дополнительная старая часть.
        $messages[] = new Message(MessageRole::USER, 'Old question');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Old answer');

        // Хвост с теми же фразами.
        $messages[] = new Message(MessageRole::USER, 'Shared question');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Shared answer');

        $trimmer = new HistoryCompactTrimmer(new TokenCounter(), 0.5);
        $result = $trimmer->trim($messages, 60);

        $this->assertNotEmpty($result);

        $first = $result[0];
        if ($first->getRole() === MessageRole::DEVELOPER->value) {
            $content = $first->getContent() ?? '';

            // В summary не должно быть дубликатов head+tail для общих фраз.
            $this->assertSame(0, substr_count($content, 'Shared question'));
            $this->assertSame(0, substr_count($content, 'Shared answer'));
            $this->assertSame(1, substr_count($content, 'Old question'));
            $this->assertSame(1, substr_count($content, 'Old answer'));
        }
    }

    public function testSummaryRespectsReasonableItemLimit(): void
    {
        $messages = [];

        // Формируем длинную голову с большим количеством уникальных вопросов/ответов.
        for ($i = 0; $i < 30; $i++) {
            $messages[] = new Message(MessageRole::USER, "Question {$i}");
            $messages[] = new Message(MessageRole::ASSISTANT, "Answer {$i}");
        }

        // Короткий хвост.
        $messages[] = new Message(MessageRole::USER, 'Tail question');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Tail answer');

        $trimmer = new HistoryCompactTrimmer(new TokenCounter(), 0.5);
        $result = $trimmer->trim($messages, 120);

        $this->assertNotEmpty($result);

        $first = $result[0];
        if ($first->getRole() === MessageRole::DEVELOPER->value) {
            $content = $first->getContent() ?? '';
            // Подсчёт количества пунктов summary по префиксу \"- \".
            $matches = [];
            preg_match_all('/^- /m', $content, $matches);
            $bulletCount = isset($matches[0]) ? count($matches[0]) : 0;

            // Хотя голова содержит 60 сообщений, summary должно содержать ограниченное количество пунктов.
            $this->assertLessThanOrEqual(25, $bulletCount);
        }
    }

    public function testTailLargerThanContextStillKeepsLastMessage(): void
    {
        $messages = [];

        // Длинная голова, чтобы гарантированно сработала компактизация.
        for ($i = 0; $i < 20; $i++) {
            $messages[] = new Message(MessageRole::USER, "Head question {$i}");
            $messages[] = new Message(MessageRole::ASSISTANT, "Head answer {$i}");
        }

        // Очень короткое окно: хвост гарантированно будет "слишком большим".
        $messages[] = new Message(MessageRole::USER, 'Tail question');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Tail answer');

        $trimmer = new HistoryCompactTrimmer(new TokenCounter(), 0.5);
        $result = $trimmer->trim($messages, 1);

        $this->assertNotEmpty($result);
        $last = $result[count($result) - 1];
        $this->assertSame('Tail answer', $last->getContent());
    }

    public function testTailHardTrimPreservesToolCallAndResultPairOnTinyWindow(): void
    {
        $messages = [];

        // Длинная голова.
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new Message(MessageRole::USER, "Head question {$i}");
            $messages[] = new Message(MessageRole::ASSISTANT, "Head answer {$i}");
        }

        // Хвост: tool-пара + пользовательский шаг, чтобы была типичная структура.
        $messages[] = new Message(MessageRole::USER, 'Before tool');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Before tool answer');

        $toolCall = new ToolCallMessage(null, []);
        $toolResult = new ToolResultMessage([]);
        $messages[] = $toolCall;
        $messages[] = $toolResult;

        $messages[] = new Message(MessageRole::USER, 'After tool');
        $messages[] = new Message(MessageRole::ASSISTANT, 'After tool answer');

        $trimmer = new HistoryCompactTrimmer(new TokenCounter(), 0.5);
        $result = $trimmer->trim($messages, 1);

        $hasPair = false;
        for ($i = 0; $i < count($result) - 1; $i++) {
            if ($result[$i] instanceof ToolCallMessage && $result[$i + 1] instanceof ToolResultMessage) {
                $hasPair = true;
                break;
            }
        }

        $this->assertTrue($hasPair, 'При жёстком срезе хвоста tool-пара не должна разрываться даже при маленьком окне');
    }
}
