<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\LlmInferenceEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\LlmInferenceLoggingSubscriber;
use app\modules\neuron\enums\EventNameEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Тесты подписчика логирования LLM-инференса.
 */
final class LlmInferenceLoggingSubscriberTest extends TestCase
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
        LlmInferenceLoggingSubscriber::reset();
    }

    protected function tearDown(): void
    {
        LlmInferenceLoggingSubscriber::reset();
        EventBus::clear();
        parent::tearDown();
    }

    /**
     * Подписчик логирует событие llm.inference.prepared с уровнем info.
     */
    public function testSubscriberLogsInferencePreparedEvent(): void
    {
        $logger = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();

        LlmInferenceLoggingSubscriber::register($logger);

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $event = (new LlmInferenceEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-04-10T10:00:00+00:00')
            ->setAgent($agentCfg)
            ->setToolsCount(3)
            ->setToolsNames(['bash', 'chunk_view', 'file_read'])
            ->setToolRequiredParams(['bash' => ['command'], 'chunk_view' => ['path']])
            ->setInstructionsPreview('You are a helpful assistant...')
            ->setInstructionsLength(1500)
            ->setUserMessagePreview('Какие инструменты доступны?')
            ->setUserMessageLength(28);

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', $event);

        // Лог ушёл в agent-логгер, не в fallback
        $this->assertCount(0, $logger->records);
        $this->assertCount(1, $agentLogger->records);
        $this->assertSame('info', $agentLogger->records[0]['level']);
        $this->assertStringStartsWith('LLM event: inference_prepared |', $agentLogger->records[0]['message']);
    }

    /**
     * Сообщение содержит тег [LlmInferenceEvent] из Stringable.
     */
    public function testLogMessageContainsStringableTag(): void
    {
        $agentLogger = $this->createMemoryLogger();

        LlmInferenceLoggingSubscriber::register($this->createMemoryLogger());

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'test-agent';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $event = (new LlmInferenceEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-04-10T10:00:00+00:00')
            ->setAgent($agentCfg)
            ->setToolsCount(2)
            ->setToolsNames(['bash', 'file_read'])
            ->setInstructionsPreview('System prompt')
            ->setInstructionsLength(500)
            ->setUserMessagePreview('Hello')
            ->setUserMessageLength(5);

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', $event);

        $this->assertStringContainsString('[LlmInferenceEvent]', $agentLogger->records[0]['message']);
    }

    /**
     * Контекст содержит все поля DTO.
     */
    public function testLogContextContainsDtoFields(): void
    {
        $agentLogger = $this->createMemoryLogger();

        LlmInferenceLoggingSubscriber::register($this->createMemoryLogger());

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('session-1');
        $agentCfg->setLogger($agentLogger);

        $event = (new LlmInferenceEventDto())
            ->setSessionKey('session-1')
            ->setRunId('run-42')
            ->setTimestamp('2026-04-10T10:00:00+00:00')
            ->setAgent($agentCfg)
            ->setToolsCount(1)
            ->setToolsNames(['bash'])
            ->setToolRequiredParams(['bash' => ['command']])
            ->setInstructionsPreview('prompt preview...')
            ->setInstructionsLength(800)
            ->setUserMessagePreview('user msg')
            ->setUserMessageLength(8);

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', $event);

        $ctx = $agentLogger->records[0]['context'];
        $this->assertSame(1, $ctx['toolsCount']);
        $this->assertSame(['bash'], $ctx['toolsNames']);
        $this->assertSame(['bash' => ['command']], $ctx['toolRequiredParams']);
        $this->assertSame('prompt preview...', $ctx['instructionsPreview']);
        $this->assertSame(800, $ctx['instructionsLength']);
        $this->assertSame('user msg', $ctx['userMessagePreview']);
        $this->assertSame(8, $ctx['userMessageLength']);
        $this->assertSame('run-42', $ctx['runId']);
        $this->assertSame('assistant', $ctx['agentName']);
    }

    /**
     * Debug-поля messagesCount и messagesSanitized включаются в контекст.
     */
    public function testLogContextIncludesDebugFields(): void
    {
        $agentLogger = $this->createMemoryLogger();

        LlmInferenceLoggingSubscriber::register($this->createMemoryLogger());

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'dbg-agent';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $event = (new LlmInferenceEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-04-10T10:00:00+00:00')
            ->setAgent($agentCfg)
            ->setToolsCount(0)
            ->setToolsNames([])
            ->setInstructionsPreview('')
            ->setInstructionsLength(0)
            ->setUserMessagePreview('')
            ->setUserMessageLength(0)
            ->setMessagesCount(5)
            ->setMessagesSanitized([['role' => 'user', 'content' => 'hi']]);

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', $event);

        $ctx = $agentLogger->records[0]['context'];
        $this->assertSame(5, $ctx['messagesCount']);
        $this->assertIsArray($ctx['messagesSanitized']);
    }

    /**
     * Debug-поля отсутствуют в контексте, если не установлены (summary-режим).
     */
    public function testLogContextExcludesDebugFieldsWhenNull(): void
    {
        $agentLogger = $this->createMemoryLogger();

        LlmInferenceLoggingSubscriber::register($this->createMemoryLogger());

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'summary-agent';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $event = (new LlmInferenceEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-04-10T10:00:00+00:00')
            ->setAgent($agentCfg)
            ->setToolsCount(2)
            ->setToolsNames(['a', 'b'])
            ->setInstructionsPreview('prompt')
            ->setInstructionsLength(100)
            ->setUserMessagePreview('msg')
            ->setUserMessageLength(3);

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', $event);

        $ctx = $agentLogger->records[0]['context'];
        $this->assertArrayNotHasKey('messagesCount', $ctx);
        $this->assertArrayNotHasKey('messagesSanitized', $ctx);
    }

    /**
     * Если agent cfg отсутствует, подписчик использует fallback logger.
     */
    public function testSubscriberUsesFallbackLoggerWhenAgentMissing(): void
    {
        $fallbackLogger = $this->createMemoryLogger();
        LlmInferenceLoggingSubscriber::register($fallbackLogger);

        $event = (new LlmInferenceEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-04-10T10:00:00+00:00')
            ->setAgent(null)
            ->setToolsCount(0)
            ->setToolsNames([])
            ->setInstructionsPreview('')
            ->setInstructionsLength(0)
            ->setUserMessagePreview('')
            ->setUserMessageLength(0);

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', $event);

        $this->assertCount(1, $fallbackLogger->records);
        $this->assertStringStartsWith('LLM event: inference_prepared |', $fallbackLogger->records[0]['message']);
    }

    /**
     * Если в payload передан agent cfg с логгером, подписчик использует его.
     */
    public function testSubscriberUsesLoggerFromAgentConfigurationWhenAvailable(): void
    {
        $fallbackLogger = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();

        LlmInferenceLoggingSubscriber::register($fallbackLogger);

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'routed-agent';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $event = (new LlmInferenceEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-04-10T10:00:00+00:00')
            ->setAgent($agentCfg)
            ->setToolsCount(1)
            ->setToolsNames(['bash'])
            ->setInstructionsPreview('prompt')
            ->setInstructionsLength(50)
            ->setUserMessagePreview('test')
            ->setUserMessageLength(4);

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', $event);

        $this->assertCount(0, $fallbackLogger->records);
        $this->assertCount(1, $agentLogger->records);
        $this->assertStringStartsWith('LLM event: inference_prepared |', $agentLogger->records[0]['message']);
        $this->assertSame('routed-agent', $agentLogger->records[0]['context']['agentName'] ?? null);
    }

    /**
     * Повторный вызов register() не дублирует обработчики.
     */
    public function testDoubleRegisterDoesNotDuplicate(): void
    {
        $fallbackLogger = $this->createMemoryLogger();
        LlmInferenceLoggingSubscriber::register($fallbackLogger);
        LlmInferenceLoggingSubscriber::register($fallbackLogger);

        $event = (new LlmInferenceEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-04-10T10:00:00+00:00')
            ->setAgent(null)
            ->setToolsCount(0)
            ->setToolsNames([])
            ->setInstructionsPreview('')
            ->setInstructionsLength(0)
            ->setUserMessagePreview('')
            ->setUserMessageLength(0);

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', $event);

        $this->assertCount(1, $fallbackLogger->records);
    }

    /**
     * Подписчик игнорирует payload неверного типа.
     */
    public function testSubscriberIgnoresNonDtoPayload(): void
    {
        $fallbackLogger = $this->createMemoryLogger();
        LlmInferenceLoggingSubscriber::register($fallbackLogger);

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', 'not a dto');

        $this->assertCount(0, $fallbackLogger->records);
    }

    /**
     * Stringable представление LlmInferenceEventDto содержит ключ tools.
     */
    public function testLlmInferenceEventDtoStringableContainsToolsCount(): void
    {
        $dto = (new LlmInferenceEventDto())
            ->setToolsCount(5)
            ->setToolsNames(['a', 'b', 'c', 'd', 'e'])
            ->setInstructionsPreview('System prompt text')
            ->setInstructionsLength(2000)
            ->setUserMessagePreview('User question here')
            ->setUserMessageLength(18);

        $str = (string) $dto;
        $this->assertStringContainsString('[LlmInferenceEvent]', $str);
        $this->assertStringContainsString('tools=5', $str);
        $this->assertStringContainsString('instructions=2000ch', $str);
    }

    /**
     * Stringable представление включает userMsg, если задан.
     */
    public function testLlmInferenceEventDtoStringableIncludesUserMsg(): void
    {
        $dto = (new LlmInferenceEventDto())
            ->setToolsCount(0)
            ->setToolsNames([])
            ->setInstructionsPreview('')
            ->setInstructionsLength(0)
            ->setUserMessagePreview('Какие у тебя инструменты?')
            ->setUserMessageLength(25);

        $str = (string) $dto;
        $this->assertStringContainsString('userMsg=', $str);
    }

    /**
     * toArray() содержит toolRequiredParams, когда заданы.
     */
    public function testToArrayIncludesToolRequiredParams(): void
    {
        $dto = (new LlmInferenceEventDto())
            ->setToolsCount(2)
            ->setToolsNames(['bash', 'read'])
            ->setToolRequiredParams(['bash' => ['command'], 'read' => ['path', 'offset']])
            ->setInstructionsPreview('prompt')
            ->setInstructionsLength(100)
            ->setUserMessagePreview('')
            ->setUserMessageLength(0);

        $arr = $dto->toArray();
        $this->assertArrayHasKey('toolRequiredParams', $arr);
        $this->assertSame(['bash' => ['command'], 'read' => ['path', 'offset']], $arr['toolRequiredParams']);
    }
}
