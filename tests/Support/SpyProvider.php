<?php

declare(strict_types=1);

namespace Tests\Support;

use app\modules\neuron\helpers\LlmCycleHelper;
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
 * Провайдер-шпион для тестов.
 *
 * Запоминает каждое обращение (label + текст последнего user message) в {@see SpyProvider::$calls}.
 * Возвращает в качестве ответа последнее user message (как EchoProvider), чтобы не требовать сети/API.
 */
final class SpyProvider implements AIProviderInterface
{
    /**
     * Список вызовов chat/stream/structured.
     *
     * @var list<array{label: string, content: string, think: bool|null}>
     */
    public static array $calls = [];

    /**
     * Параметры провайдера, которые могут динамически меняться (например, через setThink()).
     *
     * @var array<string, mixed>
     */
    protected array $parameters = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function __construct(private readonly string $label)
    {
    }

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
        self::recordCallIfNotInternal($this->label, $content, $this->resolveThinkState());

        return new AssistantMessage($content);
    }

    public function stream(Message ...$messages): Generator
    {
        $content = $this->extractLastUserContent($messages);
        self::recordCallIfNotInternal($this->label, $content, $this->resolveThinkState());

        $messageId = spl_object_hash($this);
        yield new TextChunk($messageId, $content);

        return new AssistantMessage($content);
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $array = is_array($messages) ? $messages : [$messages];
        $content = $this->extractLastUserContent($array);
        self::recordCallIfNotInternal($this->label, $content, $this->resolveThinkState());

        return new AssistantMessage($content);
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

    /**
     * Возвращает текущий think-флаг из параметров провайдера.
     */
    private function resolveThinkState(): ?bool
    {
        $options = $this->parameters['options'] ?? null;
        if (is_array($options) && array_key_exists('think', $options)) {
            return (bool) $options['think'];
        }

        $chatTemplateKwargs = $this->parameters['chat_template_kwargs'] ?? null;
        if (is_array($chatTemplateKwargs) && array_key_exists('enable_thinking', $chatTemplateKwargs)) {
            return (bool) $chatTemplateKwargs['enable_thinking'];
        }

        return null;
    }

    /**
     * Не логируем служебные реплики LlmCycleHelper (проверка статуса / повтор итога), чтобы тесты видели только тексты todo.
     *
     * @param string $label Метка провайдера.
     * @param string $content Текст последнего user-сообщения.
     * @param bool|null $think Текущий think-флаг провайдера.
     */
    private static function recordCallIfNotInternal(string $label, string $content, ?bool $think): void
    {
        if (
            $content === LlmCycleHelper::MSG_CHECK_WORK
            || $content === LlmCycleHelper::MSG_CHECK_WORK2
            || $content === LlmCycleHelper::MSG_RESULT
        ) {
            return;
        }

        self::$calls[] = ['label' => $label, 'content' => $content, 'think' => $think];
    }
}
