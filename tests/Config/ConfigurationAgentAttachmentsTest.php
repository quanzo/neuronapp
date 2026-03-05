<?php

declare(strict_types=1);

namespace Tests\Config;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\attachments\TextAttachmentDto;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Enums\MessageRole;
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

        $cfg = new class($agent) extends ConfigurationAgent {
            public function __construct(private AgentInterface $agent)
            {
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

        $cfg = new class($agent) extends ConfigurationAgent {
            public function __construct(private AgentInterface $agent)
            {
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
}

