<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\TodoEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\TodoListLoggingSubscriber;
use app\modules\neuron\enums\EventNameEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class TodoListLoggingSubscriberTest extends TestCase
{
    private function createMemoryLogger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        EventBus::clear();
        TodoListLoggingSubscriber::reset();
    }

    protected function tearDown(): void
    {
        TodoListLoggingSubscriber::reset();
        EventBus::clear();
        parent::tearDown();
    }

    public function testSubscriberLogsTodoLifecycleEvents(): void
    {
        $fallbackLogger = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();
        TodoListLoggingSubscriber::register($fallbackLogger);

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $event = (new TodoEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent($agentCfg)
            ->setTodoListName('daily')
            ->setTodoIndex(1)
            ->setTodo('do it');

        EventBus::trigger(EventNameEnum::TODO_STARTED->value, '*', $event);
        EventBus::trigger(EventNameEnum::TODO_COMPLETED->value, '*', $event);
        EventBus::trigger(EventNameEnum::TODO_FAILED->value, '*', $event->setReason('boom'));
        EventBus::trigger(EventNameEnum::TODO_GOTO_REQUESTED->value, '*', $event->setGotoTargetIndex(2));
        EventBus::trigger(EventNameEnum::TODO_GOTO_REJECTED->value, '*', $event->setReason('max'));
        EventBus::trigger(EventNameEnum::TODO_AGENT_SWITCHED->value, '*', $event->setTodoAgent('assistant'));

        $this->assertCount(0, $fallbackLogger->records);
        $this->assertCount(6, $agentLogger->records);
    }
}
