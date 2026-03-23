<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\providers;

use app\modules\neuron\helpers\LlmPayloadLogSanitizer;
use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;
use Psr\Log\LoggerInterface;

use function count;
use function is_array;

/**
 * Декоратор AI-провайдера с логированием payload запроса.
 *
 * Используется для аудита отправляемых в LLM сообщений, системного промпта
 * и описаний инструментов без модификации vendor-кода.
 */
final class LoggingAIProviderDecorator implements AIProviderInterface
{
    /**
     * Последний установленный системный промпт.
     */
    private ?string $systemPrompt = null;

    /**
     * Последний установленный набор инструментов.
     *
     * @var ToolInterface[]
     */
    private array $tools = [];

    /**
     * @param AIProviderInterface $inner Исходный провайдер.
     * @param LoggerInterface     $logger Логгер приложения.
     * @param string              $mode Режим детализации: summary|debug.
     */
    public function __construct(
        private readonly AIProviderInterface $inner,
        private readonly LoggerInterface $logger,
        private readonly string $mode = 'summary',
    ) {
    }

    /**
     * Возвращает исходный провайдер.
     *
     * @return AIProviderInterface
     */
    public function getInner(): AIProviderInterface
    {
        return $this->inner;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->systemPrompt = $prompt;
        $this->inner->systemPrompt($prompt);
        return $this;
    }

    /**
     * @param ToolInterface[] $tools
     */
    public function setTools(array $tools): AIProviderInterface
    {
        $this->tools = $tools;
        $this->inner->setTools($tools);
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return $this->inner->messageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->inner->toolPayloadMapper();
    }

    public function chat(Message ...$messages): Message
    {
        $this->logRequestPayload($messages);
        return $this->inner->chat(...$messages);
    }

    public function stream(Message ...$messages): Generator
    {
        $this->logRequestPayload($messages);
        return yield from $this->inner->stream(...$messages);
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $messageList = is_array($messages) ? $messages : [$messages];
        $this->logRequestPayload($messageList, [
            'structured_class'  => $class,
            'structured_schema' => $response_schema,
        ]);

        return $this->inner->structured($messages, $class, $response_schema);
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        $this->inner->setHttpClient($client);
        return $this;
    }

    /**
     * Логирует подготовленный payload перед отправкой в LLM.
     *
     * @param Message[] $messages Сообщения запроса.
     * @param array<string, mixed> $extra Дополнительный контекст.
     *
     * @return void
     */
    private function logRequestPayload(array $messages, array $extra = []): void
    {
        $mappedMessages = $this->messageMapper()->map($messages);
        $mappedTools = $this->tools === []
            ? []
            : $this->toolPayloadMapper()->map($this->tools);

        $preview = LlmPayloadLogSanitizer::preview($this->systemPrompt);
        $context = [
            'event'            => 'llm.request.payload',
            'provider_class'   => $this->inner::class,
            'messages_count'   => count($messages),
            'system_present'   => $this->systemPrompt !== null && $this->systemPrompt !== '',
            'system_length'    => $preview['length'],
            'system_preview'   => $preview['preview'],
            'tools_count'      => count($this->tools),
            'tools_payload'    => LlmPayloadLogSanitizer::sanitize($mappedTools, 1000, 6),
            'messages_payload' => $this->mode === 'debug'
                ? LlmPayloadLogSanitizer::sanitize($mappedMessages, 1000, 6)
                :  '[hidden_in_summary_mode]',
        ];

        if ($extra !== []) {
            $context['extra'] = LlmPayloadLogSanitizer::sanitize($extra, 1000, 6);
        }

        $this->logger->info('llm.request.payload', $context);
    }
}
