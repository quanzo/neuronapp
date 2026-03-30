<?php

declare(strict_types=1);

namespace Tests\Support;

use Generator;
use app\modules\neuron\helpers\LlmCycleHelper;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

/**
 * Минимальный тестовый провайдер, возвращающий фиксированное summary.
 *
 * Нужен для тестов оркестратора, чтобы вызов skill-суммаризации:
 * - не влиял на счётчики step/init/finish у {@see OrchestratorSpyProvider};
 * - был детерминированным (всегда возвращает один и тот же текст).
 */
final class SummarySpyProvider implements AIProviderInterface
{
    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
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
        if ($content === LlmCycleHelper::MSG_CHECK_WORK || $content === LlmCycleHelper::MSG_CHECK_WORK2) {
            return new AssistantMessage('YES');
        }

        return new AssistantMessage('SUMMARY');
    }

    public function stream(Message ...$messages): Generator
    {
        $content = $this->extractLastUserContent($messages);
        if ($content === LlmCycleHelper::MSG_CHECK_WORK || $content === LlmCycleHelper::MSG_CHECK_WORK2) {
            $messageId = spl_object_hash($this);
            yield new TextChunk($messageId, 'YES');
            return new AssistantMessage('YES');
        }

        $messageId = spl_object_hash($this);
        yield new TextChunk($messageId, 'SUMMARY');
        return new AssistantMessage('SUMMARY');
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $array = is_array($messages) ? $messages : [$messages];
        $content = $this->extractLastUserContent($array);
        if ($content === LlmCycleHelper::MSG_CHECK_WORK || $content === LlmCycleHelper::MSG_CHECK_WORK2) {
            return new AssistantMessage('YES');
        }

        return new AssistantMessage('SUMMARY');
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
            if ($m instanceof Message && $m->getRole() === \NeuronAI\Chat\Enums\MessageRole::USER->value) {
                return (string) ($m->getContent() ?? '');
            }
        }
        return '';
    }
}
