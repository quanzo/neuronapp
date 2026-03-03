<?php

declare(strict_types=1);

namespace Tests\Provider;

use app\modules\neuron\classes\neuron\providers\EchoProvider;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see EchoProvider}.
 *
 * EchoProvider — провайдер-заглушка, имитирующий работу LLM.
 * Вместо обращения к реальному API он возвращает содержимое последнего
 * сообщения пользователя (UserMessage) в качестве ответа AssistantMessage.
 *
 * Используется для тестирования и отладки без сетевых запросов и API-ключей.
 * Полностью реализует AIProviderInterface, включая chat(), stream() и structured().
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\neuron\providers\EchoProvider}
 */
class EchoProviderTest extends TestCase
{
    /**
     * Класс реализует AIProviderInterface.
     */
    public function testImplementsInterface(): void
    {
        $provider = new EchoProvider();
        $this->assertInstanceOf(AIProviderInterface::class, $provider);
    }

    // ══════════════════════════════════════════════════════════════
    //  systemPrompt / setTools — установка конфигурации
    // ══════════════════════════════════════════════════════════════

    /**
     * systemPrompt() возвращает сам объект (fluent interface).
     */
    public function testSystemPromptReturnsSelf(): void
    {
        $provider = new EchoProvider();
        $result = $provider->systemPrompt('You are helpful');
        $this->assertSame($provider, $result);
    }

    /**
     * systemPrompt(null) — допустим, возвращает сам объект.
     */
    public function testSystemPromptWithNull(): void
    {
        $provider = new EchoProvider();
        $result = $provider->systemPrompt(null);
        $this->assertSame($provider, $result);
    }

    /**
     * setTools() возвращает сам объект (fluent interface).
     */
    public function testSetToolsReturnsSelf(): void
    {
        $provider = new EchoProvider();
        $result = $provider->setTools([]);
        $this->assertSame($provider, $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  setHttpClient
    // ══════════════════════════════════════════════════════════════

    /**
     * setHttpClient() принимает любой HttpClientInterface и возвращает self.
     * EchoProvider не использует HTTP-клиент, но принимает его для совместимости.
     */
    public function testSetHttpClientReturnsSelf(): void
    {
        $provider = new EchoProvider();
        $mock = $this->createMock(\NeuronAI\HttpClient\HttpClientInterface::class);
        $result = $provider->setHttpClient($mock);
        $this->assertSame($provider, $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  messageMapper / toolPayloadMapper — заглушки маппинга
    // ══════════════════════════════════════════════════════════════

    /**
     * messageMapper() возвращает маппер-заглушку, который передаёт
     * массив сообщений без изменений.
     */
    public function testMessageMapperReturnsPassthrough(): void
    {
        $provider = new EchoProvider();
        $mapper = $provider->messageMapper();
        $input = [new UserMessage('test')];
        $this->assertSame($input, $mapper->map($input));
    }

    /**
     * toolPayloadMapper() возвращает маппер-заглушку, который передаёт
     * массив инструментов без изменений.
     */
    public function testToolPayloadMapperReturnsPassthrough(): void
    {
        $provider = new EchoProvider();
        $mapper = $provider->toolPayloadMapper();
        $input = ['tool1', 'tool2'];
        $this->assertSame($input, $mapper->map($input));
    }

    // ══════════════════════════════════════════════════════════════
    //  chat — синхронный запрос
    // ══════════════════════════════════════════════════════════════

    /**
     * При нескольких сообщениях возвращается содержимое последнего UserMessage.
     */
    public function testChatReturnsLastUserMessage(): void
    {
        $provider = new EchoProvider();
        $response = $provider->chat(
            new UserMessage('Hello'),
            new UserMessage('World'),
        );

        $this->assertInstanceOf(AssistantMessage::class, $response);
        $this->assertSame('World', $response->getContent());
    }

    /**
     * Единственное сообщение — его содержимое возвращается в ответе.
     */
    public function testChatWithSingleMessage(): void
    {
        $provider = new EchoProvider();
        $response = $provider->chat(new UserMessage('Only message'));
        $this->assertSame('Only message', $response->getContent());
    }

    /**
     * Нет ни одного UserMessage — возвращается AssistantMessage с пустым содержимым.
     */
    public function testChatWithNoUserMessageReturnsEmptyContent(): void
    {
        $provider = new EchoProvider();
        $response = $provider->chat(new AssistantMessage('assistant only'));
        $this->assertInstanceOf(AssistantMessage::class, $response);
        $content = $response->getContent();
        $this->assertTrue($content === '' || $content === null);
    }

    /**
     * Смешанный порядок сообщений (User, Assistant, User) — возвращается
     * содержимое последнего UserMessage.
     */
    public function testChatWithMixedMessages(): void
    {
        $provider = new EchoProvider();
        $response = $provider->chat(
            new UserMessage('first'),
            new AssistantMessage('response'),
            new UserMessage('second'),
        );
        $this->assertSame('second', $response->getContent());
    }

    /**
     * UserMessage с пустой строкой — AssistantMessage с пустым содержимым.
     */
    public function testChatWithEmptyUserMessage(): void
    {
        $provider = new EchoProvider();
        $response = $provider->chat(new UserMessage(''));
        $this->assertInstanceOf(AssistantMessage::class, $response);
        $content = $response->getContent();
        $this->assertTrue($content === '' || $content === null);
    }

    // ══════════════════════════════════════════════════════════════
    //  stream — потоковый ответ (Generator)
    // ══════════════════════════════════════════════════════════════

    /**
     * stream() порождает генератор, первый yield — TextChunk с содержимым
     * последнего UserMessage.
     */
    public function testStreamYieldsChunk(): void
    {
        $provider = new EchoProvider();
        $gen = $provider->stream(new UserMessage('stream test'));

        $chunk = $gen->current();
        $this->assertSame('stream test', $chunk->content);
    }

    /**
     * После завершения генератора его return-значение — AssistantMessage
     * с тем же содержимым.
     */
    public function testStreamReturnsAssistantMessage(): void
    {
        $provider = new EchoProvider();
        $gen = $provider->stream(new UserMessage('stream test'));

        $gen->current();
        $gen->next();

        $return = $gen->getReturn();
        $this->assertInstanceOf(AssistantMessage::class, $return);
        $this->assertSame('stream test', $return->getContent());
    }

    /**
     * stream() без UserMessage — TextChunk с пустым содержимым.
     */
    public function testStreamWithNoUserMessage(): void
    {
        $provider = new EchoProvider();
        $gen = $provider->stream(new AssistantMessage('not user'));

        $chunk = $gen->current();
        $this->assertSame('', $chunk->content);
    }

    // ══════════════════════════════════════════════════════════════
    //  structured — структурированный ответ
    // ══════════════════════════════════════════════════════════════

    /**
     * structured() с массивом сообщений — возвращает содержимое
     * последнего UserMessage в AssistantMessage.
     */
    public function testStructuredReturnsLastUserMessage(): void
    {
        $provider = new EchoProvider();
        $response = $provider->structured(
            [new UserMessage('structured test')],
            \stdClass::class,
            []
        );

        $this->assertInstanceOf(AssistantMessage::class, $response);
        $this->assertSame('structured test', $response->getContent());
    }

    /**
     * structured() с единственным Message (не массивом) — оборачивается
     * в массив внутри метода.
     */
    public function testStructuredWithSingleMessage(): void
    {
        $provider = new EchoProvider();
        $response = $provider->structured(
            new UserMessage('single'),
            \stdClass::class,
            []
        );

        $this->assertSame('single', $response->getContent());
    }

    /**
     * structured() без UserMessage — AssistantMessage с пустым содержимым.
     */
    public function testStructuredWithNoUserMessage(): void
    {
        $provider = new EchoProvider();
        $response = $provider->structured(
            [new AssistantMessage('not user')],
            \stdClass::class,
            []
        );

        $this->assertInstanceOf(AssistantMessage::class, $response);
        $content = $response->getContent();
        $this->assertTrue($content === '' || $content === null);
    }

    /**
     * structured() с пустым массивом сообщений — AssistantMessage с пустым содержимым.
     */
    public function testStructuredWithEmptyArray(): void
    {
        $provider = new EchoProvider();
        $response = $provider->structured(
            [],
            \stdClass::class,
            []
        );

        $this->assertInstanceOf(AssistantMessage::class, $response);
        $content = $response->getContent();
        $this->assertTrue($content === '' || $content === null);
    }
}
