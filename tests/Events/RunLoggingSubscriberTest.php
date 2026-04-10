<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\RunEventDto;
use app\modules\neuron\classes\dto\events\RunErrorEventDto;
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
    private function createMemoryLogger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

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
     * Подписчик логирует started/finished run-события через агентский логгер.
     */
    public function testSubscriberLogsRunStartedAndFinishedEvents(): void
    {
        $logger = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();

        RunLoggingSubscriber::register($logger);
        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $event = (new RunEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent($agentCfg)
            ->setType('skill')
            ->setName('search')
            ->setSteps(1);

        EventBus::trigger(EventNameEnum::RUN_STARTED->value, '*', $event);
        EventBus::trigger(EventNameEnum::RUN_FINISHED->value, '*', $event);

        // fallback-логгер не должен быть задействован — агент имеет свой логгер
        $this->assertCount(0, $logger->records);
        $this->assertCount(2, $agentLogger->records);

        // started
        $this->assertSame('info', $agentLogger->records[0]['level']);
        $this->assertStringStartsWith('Run event: started |', $agentLogger->records[0]['message']);
        $this->assertStringContainsString('[RunEvent]', $agentLogger->records[0]['message']);

        // finished
        $this->assertSame('info', $agentLogger->records[1]['level']);
        $this->assertStringStartsWith('Run event: finished |', $agentLogger->records[1]['message']);
    }

    /**
     * Подписчик логирует failed run-событие с RunErrorEventDto.
     */
    public function testSubscriberLogsRunFailedEvent(): void
    {
        $logger = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();

        RunLoggingSubscriber::register($logger);
        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $errorEvent = new RunErrorEventDto();
        $errorEvent->setSessionKey('s1');
        $errorEvent->setRunId('r1');
        $errorEvent->setTimestamp('2026-03-24T12:00:00+00:00');
        $errorEvent->setAgent($agentCfg);
        $errorEvent->setType('skill');
        $errorEvent->setName('search');
        $errorEvent->setSteps(0);
        $errorEvent->setErrorClass(\RuntimeException::class);
        $errorEvent->setErrorMessage('boom');

        EventBus::trigger(EventNameEnum::RUN_FAILED->value, '*', $errorEvent);

        $this->assertCount(0, $logger->records);
        $this->assertCount(1, $agentLogger->records);
        $this->assertSame('error', $agentLogger->records[0]['level']);
        $this->assertStringStartsWith('Run event: failed |', $agentLogger->records[0]['message']);
        $this->assertStringContainsString('[RunErrorEvent]', $agentLogger->records[0]['message']);
        $this->assertStringContainsString('RuntimeException', $agentLogger->records[0]['message']);

        // Контекст содержит поля ошибки
        $this->assertSame(\RuntimeException::class, $agentLogger->records[0]['context']['errorClass']);
        $this->assertSame('boom', $agentLogger->records[0]['context']['errorMessage']);
    }

    /**
     * RunErrorEventDto является instanceof RunEventDto — subscriber принимает оба.
     */
    public function testRunErrorEventDtoIsInstanceOfRunEventDto(): void
    {
        $dto = new RunErrorEventDto();
        $this->assertInstanceOf(RunEventDto::class, $dto);
    }

    /**
     * Без агента используется fallback-логгер.
     */
    public function testSubscriberUsesFallbackLoggerWhenAgentMissing(): void
    {
        $fallback = $this->createMemoryLogger();
        RunLoggingSubscriber::register($fallback);

        $event = (new RunEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent(null)
            ->setType('todolist')
            ->setName('test');

        EventBus::trigger(EventNameEnum::RUN_STARTED->value, '*', $event);

        $this->assertCount(1, $fallback->records);
        $this->assertStringStartsWith('Run event: started |', $fallback->records[0]['message']);
    }

    /**
     * Неверный тип payload игнорируется.
     */
    public function testSubscriberIgnoresWrongPayloadType(): void
    {
        $fallback = $this->createMemoryLogger();
        RunLoggingSubscriber::register($fallback);

        EventBus::trigger(EventNameEnum::RUN_STARTED->value, '*', 'not-a-dto');

        $this->assertCount(0, $fallback->records);
    }

    /**
     * Stringable RunEventDto выводит читаемое сообщение.
     */
    public function testRunEventDtoToString(): void
    {
        $dto = (new RunEventDto())
            ->setRunId('abc123')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setType('todolist')
            ->setName('daily')
            ->setSteps(3);

        $str = (string) $dto;
        $this->assertStringContainsString('[RunEvent]', $str);
        $this->assertStringContainsString('type=todolist', $str);
        $this->assertStringContainsString('name=daily', $str);
        $this->assertStringContainsString('steps=3', $str);
    }

    /**
     * Stringable RunErrorEventDto содержит информацию об ошибке.
     */
    public function testRunErrorEventDtoToStringContainsError(): void
    {
        $dto = new RunErrorEventDto();
        $dto->setType('todolist');
        $dto->setName('daily');
        $dto->setErrorClass(\RuntimeException::class);
        $dto->setErrorMessage('timeout');

        $str = (string) $dto;
        $this->assertStringContainsString('[RunErrorEvent]', $str);
        $this->assertStringContainsString('RuntimeException', $str);
        $this->assertStringContainsString('timeout', $str);
    }

    /**
     * Повторная регистрация подписчика не дублирует обработчики.
     */
    public function testDoubleRegisterDoesNotDuplicate(): void
    {
        $logger = $this->createMemoryLogger();
        RunLoggingSubscriber::register($logger);
        RunLoggingSubscriber::register($logger);

        $event = (new RunEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setType('todolist')
            ->setName('test');

        EventBus::trigger(EventNameEnum::RUN_STARTED->value, '*', $event);

        $this->assertCount(1, $logger->records);
    }
}
