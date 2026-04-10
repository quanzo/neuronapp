<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\SkillEventDto;
use app\modules\neuron\classes\dto\events\SkillErrorEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\SkillLoggingSubscriber;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\enums\EventNameEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Тесты подписчика skill-логирования.
 */
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

    /**
     * Подписчик логирует started/completed skill-события с параметрами в контексте.
     */
    public function testSubscriberLogsSkillStartedAndCompletedEvents(): void
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
            ->setParams(['query' => 'hello']);

        EventBus::trigger(EventNameEnum::SKILL_STARTED->value, '*', $event);
        EventBus::trigger(EventNameEnum::SKILL_COMPLETED->value, '*', $event);

        $this->assertCount(0, $fallbackLogger->records);
        $this->assertCount(2, $agentLogger->records);

        // Проверяем формат сообщения
        $this->assertStringStartsWith('Skill event: started |', $agentLogger->records[0]['message']);
        $this->assertStringStartsWith('Skill event: completed |', $agentLogger->records[1]['message']);

        // Контекст содержит params
        foreach ($agentLogger->records as $record) {
            $this->assertArrayHasKey('params', $record['context']);
            $this->assertSame(['query' => 'hello'], $record['context']['params']);
        }
    }

    /**
     * Подписчик логирует failed skill-событие с SkillErrorEventDto.
     */
    public function testSubscriberLogsSkillFailedEvent(): void
    {
        $fallbackLogger = $this->createMemoryLogger();
        $agentLogger = $this->createMemoryLogger();
        SkillLoggingSubscriber::register($fallbackLogger);

        $agentCfg = new ConfigurationAgent();
        $agentCfg->agentName = 'assistant';
        $agentCfg->setSessionKey('s1');
        $agentCfg->setLogger($agentLogger);

        $skill = new Skill('', 'search');
        $errorEvent = new SkillErrorEventDto();
        $errorEvent->setSessionKey('s1');
        $errorEvent->setRunId('r1');
        $errorEvent->setTimestamp('2026-03-24T12:00:00+00:00');
        $errorEvent->setAgent($agentCfg);
        $errorEvent->setSkill($skill);
        $errorEvent->setParams(['query' => 'hello']);
        $errorEvent->setErrorClass(\RuntimeException::class);
        $errorEvent->setErrorMessage('boom');

        EventBus::trigger(EventNameEnum::SKILL_FAILED->value, '*', $errorEvent);

        $this->assertCount(0, $fallbackLogger->records);
        $this->assertCount(1, $agentLogger->records);
        $this->assertSame('error', $agentLogger->records[0]['level']);
        $this->assertStringStartsWith('Skill event: failed |', $agentLogger->records[0]['message']);
        $this->assertStringContainsString('[SkillErrorEvent]', $agentLogger->records[0]['message']);

        // Контекст содержит errorClass и errorMessage
        $this->assertSame(\RuntimeException::class, $agentLogger->records[0]['context']['errorClass']);
        $this->assertSame('boom', $agentLogger->records[0]['context']['errorMessage']);
    }

    /**
     * SkillErrorEventDto является instanceof SkillEventDto.
     */
    public function testSkillErrorEventDtoIsInstanceOfSkillEventDto(): void
    {
        $dto = new SkillErrorEventDto();
        $this->assertInstanceOf(SkillEventDto::class, $dto);
    }

    /**
     * Stringable SkillErrorEventDto содержит информацию об ошибке.
     */
    public function testSkillErrorEventDtoToStringContainsError(): void
    {
        $skill = new Skill('', 'test-skill');
        $dto = new SkillErrorEventDto();
        $dto->setSkill($skill);
        $dto->setErrorClass(\RuntimeException::class);
        $dto->setErrorMessage('timeout');

        $str = (string) $dto;
        $this->assertStringContainsString('[SkillErrorEvent]', $str);
        $this->assertStringContainsString('skill=test-skill', $str);
        $this->assertStringContainsString('RuntimeException', $str);
    }
}
