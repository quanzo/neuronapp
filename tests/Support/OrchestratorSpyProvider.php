<?php

declare(strict_types=1);

namespace Tests\Support;

use Generator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;

/**
 * Тестовый провайдер для сценариев внешнего оркестратора.
 *
 * Поддерживает управление поведением step-итераций:
 * - выставление completed через tool `todo_completed`;
 * - принудительный выброс исключения на выбранных шагах.
 */
final class OrchestratorSpyProvider implements AIProviderInterface
{
    /**
     * @var list<array{label: string, content: string}>
     */
    public static array $calls = [];

    private static int $stepCalls = 0;
    private static int $completeOnStepCall = PHP_INT_MAX;

    /**
     * @var list<int>
     */
    private static array $failOnStepCalls = [];

    /**
     * @var ToolInterface[]
     */
    private array $tools = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$stepCalls = 0;
        self::$completeOnStepCall = PHP_INT_MAX;
        self::$failOnStepCalls = [];
    }

    public static function setCompleteOnStepCall(int $step): void
    {
        self::$completeOnStepCall = $step;
    }

    /**
     * @param list<int> $steps
     */
    public static function setFailOnStepCalls(array $steps): void
    {
        self::$failOnStepCalls = $steps;
    }

    public static function getStepCalls(): int
    {
        return self::$stepCalls;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        $this->tools = $tools;
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new class implements MessageMapperInterface {
            public function map(array $messages): array
            {
                return $messages;
            }
        };
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new class implements ToolMapperInterface {
            public function map(array $tools): array
            {
                return $tools;
            }
        };
    }

    public function chat(Message ...$messages): Message
    {
        $content = $this->extractLastUserContent($messages);
        $label = $this->resolveLabel($content);
        self::$calls[] = ['label' => $label, 'content' => $content];

        if ($label === 'step') {
            self::$stepCalls++;
            if (in_array(self::$stepCalls, self::$failOnStepCalls, true)) {
                // Error, а не Exception: иначе WaitSuccess в sendMessage перехватывает RuntimeException и повторяет chat(),
                // из‑за чего stepCalls растёт и условие fail сбивается.
                throw new \Error('Step failure from OrchestratorSpyProvider');
            }

            if (self::$stepCalls >= self::$completeOnStepCall) {
                $this->invokeTodoCompleted('done', 'set by test provider');
            } else {
                $this->invokeTodoCompleted('not_done', 'set by test provider');
            }
        }

        return new AssistantMessage($content);
    }

    public function stream(Message ...$messages): Generator
    {
        $content = $this->extractLastUserContent($messages);
        $messageId = spl_object_hash($this);
        yield new TextChunk($messageId, $content);
        return new AssistantMessage($content);
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $array = is_array($messages) ? $messages : [$messages];
        return $this->chat(...$array);
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    /**
     * @param Message[] $messages
     */
    private function extractLastUserContent(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i];
            if ($m instanceof Message && $m->getRole() === MessageRole::USER->value) {
                return (string) ($m->getContent() ?? '');
            }
        }
        return '';
    }

    private function resolveLabel(string $content): string
    {
        $c = strtoupper($content);
        if (str_contains($c, 'INIT')) {
            return 'init';
        }
        if (str_contains($c, 'STEP')) {
            return 'step';
        }
        if (str_contains($c, 'FINISH')) {
            return 'finish';
        }
        return 'other';
    }

    private function invokeTodoCompleted(string $status, string $reason): void
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() !== 'todo_completed') {
                continue;
            }
            $tool($status, $reason);
            return;
        }
    }
}
