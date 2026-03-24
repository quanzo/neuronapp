<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\dto\events\RunEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\RunLoggingSubscriber;
use app\modules\neuron\enums\EventNameEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Тесты подписчика run-логирования.
 */
final class RunLoggingSubscriberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EventBus::clear();
        RunLoggingSubscriber::reset();
    }

    protected function tearDown(): void
    {
        RunLoggingSubscriber::reset();
        EventBus::clear();
        parent::tearDown();
    }

    /**
     * Подписчик логирует started/finished/failed run-события.
     */
    public function testSubscriberLogsRunLifecycleEvents(): void
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

        RunLoggingSubscriber::register($logger);

        $event = (new RunEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setType('skill')
            ->setName('search')
            ->setSteps(1)
            ->setSuccess(true);

        EventBus::trigger(EventNameEnum::RUN_STARTED->value, '*', $event);
        EventBus::trigger(EventNameEnum::RUN_FINISHED->value, '*', $event);
        EventBus::trigger(
            EventNameEnum::RUN_FAILED->value,
            '*',
            $event->setSuccess(false)->setErrorClass(\RuntimeException::class)->setErrorMessage('boom')
        );

        $this->assertCount(3, $logger->records);
        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertSame('Run event: started', $logger->records[0]['message']);
        $this->assertSame('info', $logger->records[1]['level']);
        $this->assertSame('Run event: finished', $logger->records[1]['message']);
        $this->assertSame('error', $logger->records[2]['level']);
        $this->assertSame('Run event: failed', $logger->records[2]['message']);
    }
}
