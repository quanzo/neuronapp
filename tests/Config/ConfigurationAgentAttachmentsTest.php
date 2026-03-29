<?php

declare(strict_types=1);

namespace Tests\Config;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\attachments\TextAttachmentDto;
use app\modules\neuron\classes\dto\events\AgentMessageEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

/**
 * Тесты поддержки вложений в {@see ConfigurationAgent::sendMessageWithAttachments()}.
 *
 * Проверяем, что:\n
 * - DTO вложений преобразуются в content blocks через getContentBlock();\n
 * - готовые ContentBlockInterface можно передавать напрямую;\n
 * - итоговое сообщение передаётся в Agent::chat()/Agent::structured() уже с прикреплёнными блоками.
 */
final class ConfigurationAgentAttachmentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EventBus::clear();
    }

    protected function tearDown(): void
    {
        EventBus::clear();
        parent::tearDown();
    }

    public function testSendMessageWithAttachmentsAddsBlocksAndCallsChat(): void
    {
        $handler = $this->createMock(AgentHandler::class);
        $handler->method('getMessage')->willReturn(new Message(MessageRole::ASSISTANT, 'ok'));

        $agent = $this->createMock(AgentInterface::class);

        $captured = null;
        $agent->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (mixed $message) use (&$captured): bool {
                if (!$message instanceof Message) {
                    return false;
                }
                $captured = $message;
                return true;
            }))
            ->willReturn($handler);

        $cfg = new class ($agent) extends ConfigurationAgent {
            public function __construct(private AgentInterface $agent)
            {
                // sendMessageWithAttachments делает снимок истории до WaitSuccess; без in-memory истории потребовался бы ConfigurationApp.
                $this->setChatHistory(new InMemoryChatHistory());
            }

            public function getAgent(): AgentInterface
            {
                return $this->agent;
            }
        };

        $message = new Message(MessageRole::USER, 'hi');

        $dtoAttachment = new TextAttachmentDto('from-dto', 'lbl');
        $directBlock = new TextContent('from-block');

        $result = $cfg->sendMessageWithAttachments($message, [$dtoAttachment, $directBlock]);
        $this->assertInstanceOf(Message::class, $result);
        $this->assertSame('ok', $result->getContent());

        $this->assertInstanceOf(Message::class, $captured);
        $blocks = $captured->getContentBlocks();
        $this->assertCount(3, $blocks);
        $this->assertInstanceOf(TextContent::class, $blocks[0]); // original "hi"
        $this->assertInstanceOf(TextContent::class, $blocks[1]); // from dto
        $this->assertInstanceOf(TextContent::class, $blocks[2]); // from direct block
        $this->assertSame('hi', $blocks[0]->content);
        $this->assertSame('from-dto', $blocks[1]->content);
        $this->assertSame('from-block', $blocks[2]->content);
    }

    public function testSendMessageWithAttachmentsCallsStructuredWhenResponseStructClassSet(): void
    {
        $agent = $this->createMock(AgentInterface::class);

        $captured = null;
        $agent->expects($this->once())
            ->method('structured')
            ->with($this->callback(function (mixed $message) use (&$captured): bool {
                if (!$message instanceof Message) {
                    return false;
                }
                $captured = $message;
                return true;
            }), 'SomeClass', 2)
            ->willReturn((object) ['value' => 'structured-ok']);

        $cfg = new class ($agent) extends ConfigurationAgent {
            public function __construct(private AgentInterface $agent)
            {
                $this->setChatHistory(new InMemoryChatHistory());
            }

            public function getAgent(): AgentInterface
            {
                return $this->agent;
            }
        };

        $cfg->reponseStructClass = 'SomeClass';

        $message = new Message(MessageRole::USER, 'hi');
        $dtoAttachment = new TextAttachmentDto('from-dto');

        $result = $cfg->sendMessageWithAttachments($message, [$dtoAttachment]);
        $this->assertIsObject($result);

        $this->assertInstanceOf(Message::class, $captured);
        $this->assertCount(2, $captured->getContentBlocks());
        $this->assertSame('hi', $captured->getContentBlocks()[0]->content);
        $this->assertSame('from-dto', $captured->getContentBlocks()[1]->content);
    }

    public function testSendMessageWithAttachmentsEmitsStartedAndCompletedEvents(): void
    {
        $handler = $this->createMock(AgentHandler::class);
        $handler->method('getMessage')->willReturn(new Message(MessageRole::ASSISTANT, 'ok'));

        $agent = $this->createMock(AgentInterface::class);
        $agent->method('chat')->willReturn($handler);

        $cfg = new class ($agent) extends ConfigurationAgent {
            public function __construct(private AgentInterface $agent)
            {
            }

            public function getAgent(): AgentInterface
            {
                return $this->agent;
            }
        };
        $cfg->agentName = 'assistant';
        $cfg->setSessionKey('s1');
        $cfg->setChatHistory(new InMemoryChatHistory());

        $events = [];
        EventBus::on(EventNameEnum::AGENT_MESSAGE_STARTED->value, static function (mixed $payload) use (&$events): void {
            $events[] = ['type' => 'started', 'payload' => $payload];
        }, '*');
        EventBus::on(EventNameEnum::AGENT_MESSAGE_COMPLETED->value, static function (mixed $payload) use (&$events): void {
            $events[] = ['type' => 'completed', 'payload' => $payload];
        }, '*');

        $cfg->sendMessageWithAttachments(new Message(MessageRole::USER, 'hi'), [new TextAttachmentDto('a1')]);

        $this->assertCount(2, $events);
        $this->assertSame('started', $events[0]['type']);
        $this->assertSame('completed', $events[1]['type']);
        $this->assertInstanceOf(AgentMessageEventDto::class, $events[0]['payload']);
        $this->assertSame(1, $events[0]['payload']->getAttachmentsCount());
        $this->assertTrue($events[1]['payload']->isSuccess());
    }

    public function testSendMessageWithAttachmentsEmitsFailedEventOnException(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('chat')->willThrowException(new \RuntimeException('boom'));

        $cfg = new class ($agent) extends ConfigurationAgent {
            public function __construct(private AgentInterface $agent)
            {
            }

            public function getAgent(): AgentInterface
            {
                return $this->agent;
            }
        };
        $cfg->setSessionKey('s1');
        $cfg->setChatHistory(new InMemoryChatHistory());

        $failedPayload = null;
        EventBus::on(EventNameEnum::AGENT_MESSAGE_FAILED->value, static function (mixed $payload) use (&$failedPayload): void {
            $failedPayload = $payload;
        }, '*');

        try {
            $cfg->sendMessageWithAttachments(new Message(MessageRole::USER, 'hi'), []);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertInstanceOf(AgentMessageEventDto::class, $failedPayload);
        $this->assertFalse($failedPayload->isSuccess());
        $this->assertSame(\RuntimeException::class, $failedPayload->getErrorClass());
    }
}
