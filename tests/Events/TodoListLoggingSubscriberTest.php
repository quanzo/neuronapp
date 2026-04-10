<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\TodoEventDto;
use app\modules\neuron\classes\dto\events\TodoErrorEventDto;
use app\modules\neuron\classes\dto\events\TodoGotoRejectedEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\TodoListLoggingSubscriber;
use app\modules\neuron\enums\EventNameEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Тесты подписчика todo-логирования.
 */
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

    /**
     * Подписчик логирует started/completed/goto_requested/agent_switched todo-события.
     */
    public function testSubscriberLogsTodoNormalLifecycleEvents(): void
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
        EventBus::trigger(EventNameEnum::TODO_GOTO_REQUESTED->value, '*', $event->setGotoTargetIndex(2));
        EventBus::trigger(EventNameEnum::TODO_AGENT_SWITCHED->value, '*', $event->setTodoAgent('assistant'));

        $this->assertCount(0, $fallbackLogger->records);
        $this->assertCount(4, $agentLogger->records);

        $this->assertStringStartsWith('Todo event: started |', $agentLogger->records[0]['message']);
        $this->assertStringStartsWith('Todo event: completed |', $agentLogger->records[1]['message']);
        $this->assertStringStartsWith('Todo event: goto_requested |', $agentLogger->records[2]['message']);
        $this->assertStringStartsWith('Todo event: agent_switched |', $agentLogger->records[3]['message']);
    }

    /**
     * Подписчик логирует failed todo-событие с TodoErrorEventDto.
     */
    public function testSubscriberLogsTodoFailedEvent(): void
    {
        $agentLogger = $this->createMemoryLogger();
        TodoListLoggingSubscriber::register($this->createMemoryLogger());

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $errorEvent = new TodoErrorEventDto();
        $errorEvent->setSessionKey('s1');
        $errorEvent->setRunId('r1');
        $errorEvent->setTimestamp('2026-03-24T12:00:00+00:00');
        $errorEvent->setAgent($agentCfg);
        $errorEvent->setTodoListName('daily');
        $errorEvent->setTodoIndex(1);
        $errorEvent->setTodo('do it');
        $errorEvent->setReason('process killed');
        $errorEvent->setErrorClass(\RuntimeException::class);
        $errorEvent->setErrorMessage('process killed');

        EventBus::trigger(EventNameEnum::TODO_FAILED->value, '*', $errorEvent);

        $this->assertCount(1, $agentLogger->records);
        $this->assertSame('error', $agentLogger->records[0]['level']);
        $this->assertStringStartsWith('Todo event: failed |', $agentLogger->records[0]['message']);
        $this->assertStringContainsString('[TodoErrorEvent]', $agentLogger->records[0]['message']);
    }

    /**
     * Подписчик логирует goto_rejected событие с TodoGotoRejectedEventDto.
     */
    public function testSubscriberLogsTodoGotoRejectedEvent(): void
    {
        $agentLogger = $this->createMemoryLogger();
        TodoListLoggingSubscriber::register($this->createMemoryLogger());

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $rejectEvent = new TodoGotoRejectedEventDto();
        $rejectEvent->setSessionKey('s1');
        $rejectEvent->setRunId('r1');
        $rejectEvent->setTimestamp('2026-03-24T12:00:00+00:00');
        $rejectEvent->setAgent($agentCfg);
        $rejectEvent->setTodoListName('daily');
        $rejectEvent->setTodoIndex(5);
        $rejectEvent->setGotoTargetIndex(0);
        $rejectEvent->setGotoTransitionsCount(101);
        $rejectEvent->setReason('max_goto_transitions');

        EventBus::trigger(EventNameEnum::TODO_GOTO_REJECTED->value, '*', $rejectEvent);

        $this->assertCount(1, $agentLogger->records);
        $this->assertSame('warning', $agentLogger->records[0]['level']);
        $this->assertStringStartsWith('Todo event: goto_rejected |', $agentLogger->records[0]['message']);
        $this->assertStringContainsString('[TodoGotoRejectedEvent]', $agentLogger->records[0]['message']);
    }

    /**
     * TodoErrorEventDto и TodoGotoRejectedEventDto наследуют TodoEventDto.
     */
    public function testErrorDtosAreInstanceOfTodoEventDto(): void
    {
        $this->assertInstanceOf(TodoEventDto::class, new TodoErrorEventDto());
        $this->assertInstanceOf(TodoEventDto::class, new TodoGotoRejectedEventDto());
        $this->assertInstanceOf(TodoErrorEventDto::class, new TodoGotoRejectedEventDto());
    }

    /**
     * Stringable TodoGotoRejectedEventDto содержит goto-информацию.
     */
    public function testTodoGotoRejectedEventDtoToStringContainsGotoInfo(): void
    {
        $dto = new TodoGotoRejectedEventDto();
        $dto->setTodoListName('daily');
        $dto->setTodoIndex(5);
        $dto->setGotoTargetIndex(0);
        $dto->setGotoTransitionsCount(101);
        $dto->setReason('max_goto_transitions');

        $str = (string) $dto;
        $this->assertStringContainsString('[TodoGotoRejectedEvent]', $str);
        $this->assertStringContainsString('gotoTarget=0', $str);
        $this->assertStringContainsString('gotoTransitions=101', $str);
    }
}
