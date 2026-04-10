<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\ToolEventDto;
use app\modules\neuron\classes\dto\events\ToolErrorEventDto;
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
    /**
     * Создает in-memory logger для тестов.
     */
    private function createMemoryLogger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
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
    }

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
     * Подписчик логирует started/completed tool-события.
     */
    public function testSubscriberLogsToolStartedAndCompletedEvents(): void
    {
        $logger = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();

        ToolLoggingSubscriber::register($logger);
        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $event = (new ToolEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent($agentCfg)
            ->setToolName('bash');

        EventBus::trigger(EventNameEnum::TOOL_STARTED->value, '*', $event);
        EventBus::trigger(EventNameEnum::TOOL_COMPLETED->value, '*', $event);

        $this->assertCount(0, $logger->records);
        $this->assertCount(2, $agentLogger->records);
        $this->assertSame('info', $agentLogger->records[0]['level']);
        $this->assertStringStartsWith('Tool event: started |', $agentLogger->records[0]['message']);
        $this->assertSame('info', $agentLogger->records[1]['level']);
        $this->assertStringStartsWith('Tool event: completed |', $agentLogger->records[1]['message']);
        $this->assertSame('assistant', $agentLogger->records[0]['context']['agentName'] ?? null);
    }

    /**
     * Подписчик логирует failed tool-событие с ToolErrorEventDto.
     */
    public function testSubscriberLogsToolFailedEvent(): void
    {
        $agentLogger = $this->createMemoryLogger();

        ToolLoggingSubscriber::register($this->createMemoryLogger());
        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $errorEvent = new ToolErrorEventDto();
        $errorEvent->setSessionKey('s1');
        $errorEvent->setRunId('r1');
        $errorEvent->setTimestamp('2026-03-24T12:00:00+00:00');
        $errorEvent->setAgent($agentCfg);
        $errorEvent->setToolName('bash');
        $errorEvent->setErrorClass(\RuntimeException::class);
        $errorEvent->setErrorMessage('boom');

        EventBus::trigger(EventNameEnum::TOOL_FAILED->value, '*', $errorEvent);

        $this->assertCount(1, $agentLogger->records);
        $this->assertSame('error', $agentLogger->records[0]['level']);
        $this->assertStringStartsWith('Tool event: failed |', $agentLogger->records[0]['message']);
        $this->assertStringContainsString('[ToolErrorEvent]', $agentLogger->records[0]['message']);
        $this->assertSame(\RuntimeException::class, $agentLogger->records[0]['context']['errorClass']);
        $this->assertSame('boom', $agentLogger->records[0]['context']['errorMessage']);
    }

    /**
     * Если в payload передан agent cfg с логгером, подписчик использует его.
     */
    public function testSubscriberUsesLoggerFromAgentConfigurationWhenAvailable(): void
    {
        $fallbackLogger = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();

        ToolLoggingSubscriber::register($fallbackLogger);

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $event = (new ToolEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent($agentCfg)
            ->setToolName('bash');

        EventBus::trigger(EventNameEnum::TOOL_STARTED->value, '*', $event);

        $this->assertCount(0, $fallbackLogger->records);
        $this->assertCount(1, $agentLogger->records);
        $this->assertStringStartsWith('Tool event: started |', $agentLogger->records[0]['message']);
        $this->assertSame('assistant', $agentLogger->records[0]['context']['agentName'] ?? null);
    }

    /**
     * Если agent cfg отсутствует, подписчик использует fallback logger.
     */
    public function testSubscriberUsesFallbackLoggerWhenAgentMissing(): void
    {
        $fallbackLogger = $this->createMemoryLogger();
        ToolLoggingSubscriber::register($fallbackLogger);

        $event = (new ToolEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent(null)
            ->setToolName('bash');

        EventBus::trigger(EventNameEnum::TOOL_STARTED->value, '*', $event);

        $this->assertCount(1, $fallbackLogger->records);
        $this->assertStringStartsWith('Tool event: started |', $fallbackLogger->records[0]['message']);
    }

    /**
     * ToolErrorEventDto является instanceof ToolEventDto.
     */
    public function testToolErrorEventDtoIsInstanceOfToolEventDto(): void
    {
        $dto = new ToolErrorEventDto();
        $this->assertInstanceOf(ToolEventDto::class, $dto);
    }

    /**
     * Stringable ToolErrorEventDto содержит информацию об ошибке.
     */
    public function testToolErrorEventDtoToStringContainsError(): void
    {
        $dto = new ToolErrorEventDto();
        $dto->setToolName('bash');
        $dto->setErrorClass(\RuntimeException::class);
        $dto->setErrorMessage('command failed');

        $str = (string) $dto;
        $this->assertStringContainsString('[ToolErrorEvent]', $str);
        $this->assertStringContainsString('tool=bash', $str);
        $this->assertStringContainsString('RuntimeException', $str);
        $this->assertStringContainsString('command failed', $str);
    }
}
