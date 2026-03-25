<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\OrchestratorEventDto;
use app\modules\neuron\classes\dto\events\OrchestratorResumeHistoryMissingEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\OrchestratorLoggingSubscriber;
use app\modules\neuron\enums\EventNameEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Тесты подписчика логирования событий оркестратора.
 */
final class OrchestratorLoggingSubscriberTest extends TestCase
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
        OrchestratorLoggingSubscriber::reset();
    }

    protected function tearDown(): void
    {
        OrchestratorLoggingSubscriber::reset();
        EventBus::clear();
        parent::tearDown();
    }

    /**
     * Событие resume_history_missing логируется warning в логгер агента.
     */
    public function testSubscriberLogsWarningUsingAgentLogger(): void
    {
        $fallback = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();

        OrchestratorLoggingSubscriber::register($fallback);

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'default';
        $agentCfg->setSessionKey('sess-1');
        $agentCfg->setLogger($agentLogger);

        $dto = (new OrchestratorResumeHistoryMissingEventDto())
            ->setSessionKey('sess-1')
            ->setRunId('run-1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent($agentCfg)
            ->setTodolistName('step-list')
            ->setLastCompletedTodoIndex(0)
            ->setStartFromTodoIndex(1);

        EventBus::trigger(EventNameEnum::ORCHESTRATOR_RESUME_HISTORY_MISSING->value, '*', $dto);

        $this->assertCount(0, $fallback->records);
        $this->assertCount(1, $agentLogger->records);
        $this->assertSame('warning', $agentLogger->records[0]['level']);
        $this->assertSame('Orchestrator event: resume_history_missing', $agentLogger->records[0]['message']);
        $this->assertSame('step-list', $agentLogger->records[0]['context']['todolistName']);
        $this->assertSame(1, $agentLogger->records[0]['context']['startFromTodoIndex']);
        $this->assertSame('history_message_count_absent', $agentLogger->records[0]['context']['reason']);
    }

    /**
     * Без агента в DTO используется fallback-логгер.
     */
    public function testSubscriberUsesFallbackLoggerWhenAgentMissing(): void
    {
        $fallback = $this->createMemoryLogger();

        OrchestratorLoggingSubscriber::register($fallback);

        $dto = (new OrchestratorResumeHistoryMissingEventDto())
            ->setSessionKey('sess-2')
            ->setRunId('run-2')
            ->setTimestamp('2026-03-24T12:00:01+00:00')
            ->setAgent(null)
            ->setTodolistName('init-list')
            ->setLastCompletedTodoIndex(-1)
            ->setStartFromTodoIndex(0);

        EventBus::trigger(EventNameEnum::ORCHESTRATOR_RESUME_HISTORY_MISSING->value, '*', $dto);

        $this->assertCount(1, $fallback->records);
        $this->assertSame('warning', $fallback->records[0]['level']);
    }

    /**
     * Неверный тип payload не приводит к записям в лог и не падает.
     */
    public function testSubscriberIgnoresWrongPayloadType(): void
    {
        $fallback = $this->createMemoryLogger();
        OrchestratorLoggingSubscriber::register($fallback);

        EventBus::trigger(EventNameEnum::ORCHESTRATOR_RESUME_HISTORY_MISSING->value, '*', 'not-a-dto');

        $this->assertCount(0, $fallback->records);
    }

    /**
     * События жизненного цикла оркестратора (OrchestratorEventDto) пишутся в fallback-лог с нужными уровнями.
     */
    public function testSubscriberLogsLifecycleOrchestratorEvents(): void
    {
        $fallback = $this->createMemoryLogger();
        OrchestratorLoggingSubscriber::register($fallback);

        $base = (new OrchestratorEventDto())
            ->setSessionKey('sess-orch')
            ->setRunId('run-orch')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent(null)
            ->setRestartCount(0)
            ->setIterations(1);

        EventBus::trigger(EventNameEnum::ORCHESTRATOR_CYCLE_STARTED->value, '*', clone $base);
        EventBus::trigger(
            EventNameEnum::ORCHESTRATOR_STEP_COMPLETED->value,
            '*',
            (clone $base)->setCompletedNormalized(0)->setCompletedRaw('not_done')
        );
        EventBus::trigger(
            EventNameEnum::ORCHESTRATOR_COMPLETED->value,
            '*',
            (clone $base)->setReason('completed')->setSuccess(true)
        );
        EventBus::trigger(
            EventNameEnum::ORCHESTRATOR_FAILED->value,
            '*',
            (clone $base)->setReason('error')->setSuccess(false)->setErrorClass(\RuntimeException::class)->setErrorMessage('x')
        );
        EventBus::trigger(
            EventNameEnum::ORCHESTRATOR_RESTARTED->value,
            '*',
            (clone $base)->setReason('restart_after_error')->setSuccess(false)
        );

        $this->assertCount(5, $fallback->records);
        $this->assertSame('info', $fallback->records[0]['level']);
        $this->assertSame('Orchestrator event: cycle_started', $fallback->records[0]['message']);
        $this->assertSame('info', $fallback->records[1]['level']);
        $this->assertSame('Orchestrator event: step_completed', $fallback->records[1]['message']);
        $this->assertSame('info', $fallback->records[2]['level']);
        $this->assertSame('Orchestrator event: completed', $fallback->records[2]['message']);
        $this->assertSame('error', $fallback->records[3]['level']);
        $this->assertSame('Orchestrator event: failed', $fallback->records[3]['message']);
        $this->assertSame('warning', $fallback->records[4]['level']);
        $this->assertSame('Orchestrator event: restarted', $fallback->records[4]['message']);
    }

    /**
     * Неверный тип payload для lifecycle-события не ломает обработчик.
     */
    public function testSubscriberIgnoresWrongPayloadForCycleStarted(): void
    {
        $fallback = $this->createMemoryLogger();
        OrchestratorLoggingSubscriber::register($fallback);

        EventBus::trigger(EventNameEnum::ORCHESTRATOR_CYCLE_STARTED->value, '*', []);

        $this->assertCount(0, $fallback->records);
    }
}
