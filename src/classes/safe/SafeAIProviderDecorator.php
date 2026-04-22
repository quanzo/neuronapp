<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe;

use Generator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use Psr\Log\LoggerInterface;

use function array_map;
use function is_array;

/**
 * Декоратор AI-провайдера, применяющий InputSafe/OutputSafe.
 */
class SafeAIProviderDecorator implements AIProviderInterface
{
    /**
     * @param AIProviderInterface $inner Базовый провайдер.
     * @param InputSafe|null      $inputSafe Фильтрация входного текста.
     * @param OutputSafe|null     $outputSafe Фильтрация выходного текста.
     * @param LoggerInterface|null $logger Логгер для сигналов о редактировании output.
     */
    public function __construct(
        private readonly AIProviderInterface $inner,
        private readonly ?InputSafe $inputSafe = null,
        private readonly ?OutputSafe $outputSafe = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Возвращает исходный провайдер.
     */
    public function getInner(): AIProviderInterface
    {
        return $this->inner;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->inner->systemPrompt($prompt);
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
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
        $safeMessages = $this->sanitizeIncomingMessages($messages);
        $response = $this->inner->chat(...$safeMessages);

        return $this->sanitizeOutgoingMessage($response);
    }

    public function stream(Message ...$messages): Generator
    {
        $safeMessages = $this->sanitizeIncomingMessages($messages);
        $generator = $this->inner->stream(...$safeMessages);

        foreach ($generator as $chunk) {
            yield $chunk;
        }

        $returnMessage = $generator->getReturn();
        if ($returnMessage instanceof Message) {
            return $this->sanitizeOutgoingMessage($returnMessage);
        }

        return $returnMessage;
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        if (is_array($messages)) {
            $safeMessages = $this->sanitizeIncomingMessages($messages);
            $response = $this->inner->structured($safeMessages, $class, $response_schema);
            return $this->sanitizeOutgoingMessage($response);
        }

        $safeMessage = $this->sanitizeIncomingMessage($messages);
        $response = $this->inner->structured($safeMessage, $class, $response_schema);

        return $this->sanitizeOutgoingMessage($response);
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        $this->inner->setHttpClient($client);
        return $this;
    }

    /**
     * @param list<Message> $messages Список сообщений.
     *
     * @return list<Message>
     */
    private function sanitizeIncomingMessages(array $messages): array
    {
        return array_map(fn (Message $message): Message => $this->sanitizeIncomingMessage($message), $messages);
    }

    /**
     * Санитизирует и валидирует входное сообщение.
     */
    private function sanitizeIncomingMessage(Message $message): Message
    {
        if ($this->inputSafe === null) {
            return $message;
        }

        $content = $message->getContent();
        if (!is_string($content)) {
            return $message;
        }

        $safeContent = $this->inputSafe->sanitizeAndAssert($content);
        if ($safeContent === $content) {
            return $message;
        }

        return $this->copyMessageWithContent($message, $safeContent);
    }

    /**
     * Санитизирует выходное сообщение LLM.
     */
    private function sanitizeOutgoingMessage(Message $message): Message
    {
        if ($this->outputSafe === null) {
            return $message;
        }

        $content = $message->getContent();
        if (!is_string($content)) {
            return $message;
        }

        $result = $this->outputSafe->sanitize($content);
        if (!$result->hasViolations()) {
            return $message;
        }

        if ($this->logger !== null) {
            $this->logger->warning(
                'llm.output.redacted',
                [
                    'event'      => 'llm.output.redacted',
                    'violations' => array_map(
                        static fn ($violation): array => $violation->toArray(),
                        $result->getViolations()
                    ),
                ]
            );
        }

        return $this->copyMessageWithContent($message, $result->getSafeText());
    }

    /**
     * Возвращает копию сообщения с новым текстовым контентом.
     */
    private function copyMessageWithContent(Message $message, string $newContent): Message
    {
        if (method_exists($message, 'setContents')) {
            $clone = clone $message;
            $clone->setContents($newContent);
            return $clone;
        }

        if (method_exists($message, 'setContent')) {
            $clone = clone $message;
            $clone->setContent($newContent);
            return $clone;
        }

        return new Message(MessageRole::from($message->getRole()), $newContent);
    }
}
