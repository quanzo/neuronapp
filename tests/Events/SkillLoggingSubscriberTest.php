<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\SkillEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\SkillLoggingSubscriber;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\enums\EventNameEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class SkillLoggingSubscriberTest extends TestCase
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
        SkillLoggingSubscriber::reset();
    }

    protected function tearDown(): void
    {
        SkillLoggingSubscriber::reset();
        EventBus::clear();
        parent::tearDown();
    }

    public function testSubscriberLogsSkillLifecycleEvents(): void
    {
        $fallbackLogger = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();
        SkillLoggingSubscriber::register($fallbackLogger);

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $skill = new Skill('', 'search');
        $event = (new SkillEventDto())
            ->setSessionKey('s1')
            ->setRunId('r1')
            ->setTimestamp('2026-03-24T12:00:00+00:00')
            ->setAgent($agentCfg)
            ->setSkill($skill)
            ->setParams(['query' => 'hello'])
            ->setSuccess(true);

        EventBus::trigger(EventNameEnum::SKILL_STARTED->value, '*', $event);
        EventBus::trigger(EventNameEnum::SKILL_COMPLETED->value, '*', $event);
        EventBus::trigger(EventNameEnum::SKILL_FAILED->value, '*', $event->setSuccess(false)->setErrorMessage('boom'));

        $this->assertCount(0, $fallbackLogger->records);
        $this->assertCount(3, $agentLogger->records);
    }
}
