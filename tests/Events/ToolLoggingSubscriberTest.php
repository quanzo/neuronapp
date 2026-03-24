<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\ToolEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\ToolLoggingSubscriber;
use app\modules\neuron\enums\EventNameEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Тесты подписчика tool-логирования.
 */
final class ToolLoggingSubscriberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EventBus::clear();
        ToolLoggingSubscriber::reset();
    }

    protected function tearDown(): void
    {
        ToolLoggingSubscriber::reset();
        EventBus::clear();
        parent::tearDown();
    }

    /**
     * Подписчик логирует started/completed/failed tool-события.
     */
    public function testSubscriberLogsToolLifecycleEvents(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<string,mixed> $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        ToolLoggingSubscriber::register($logger);
        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';

        $event = (new ToolEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent($agentCfg)
            ->setToolName('bash')
            ->setSuccess(true);

        EventBus::trigger(EventNameEnum::TOOL_STARTED->value, '*', $event);
        EventBus::trigger(EventNameEnum::TOOL_COMPLETED->value, '*', $event);
        EventBus::trigger(
            EventNameEnum::TOOL_FAILED->value,
            '*',
            $event->setSuccess(false)->setErrorClass(\RuntimeException::class)->setErrorMessage('boom')
        );

        $this->assertCount(3, $logger->records);
        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertSame('Tool event: started', $logger->records[0]['message']);
        $this->assertSame('info', $logger->records[1]['level']);
        $this->assertSame('Tool event: completed', $logger->records[1]['message']);
        $this->assertSame('error', $logger->records[2]['level']);
        $this->assertSame('Tool event: failed', $logger->records[2]['message']);
        $this->assertSame('assistant', $logger->records[0]['context']['agentName'] ?? null);
    }
}
