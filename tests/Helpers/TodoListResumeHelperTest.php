<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\run\RunStateDto;
use app\modules\neuron\helpers\RunStateCheckpointHelper;
use app\modules\neuron\helpers\TodoListResumeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see TodoListResumeHelper}.
 *
 * Проверяют единый расчёт resume-плана и применение отката истории по checkpoint.
 */
final class TodoListResumeHelperTest extends TestCase
{
    private string $tmpDir;
    private ConfigurationApp $configApp;
    private ConfigurationAgent $agentCfg;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_resume_helper_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);

        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]));
        $this->configApp = ConfigurationApp::getInstance();
        $this->configApp->setSessionKey('20250301-143022-123456-0');

        $this->agentCfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
            'contextWindow' => 50000,
        ], $this->configApp);
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * Проверяет формирование resume-плана на наборе из 10+ кейсов.
     */
    #[DataProvider('provideResumePlanCases')]
    public function testBuildPlan(
        string $comment,
        ?RunStateDto $checkpoint,
        string $todolistName,
        ?string $expectedSessionKey,
        bool $expectedResumeAvailable,
        string $expectedReason,
        int $expectedStartFrom
    ): void {
        RunStateCheckpointHelper::delete($this->configApp->getSessionKey(), RunStateDto::DEF_AGENT_NAME);

        if ($checkpoint !== null) {
            RunStateCheckpointHelper::write($checkpoint);
        }

        $plan = TodoListResumeHelper::buildPlan($this->agentCfg, $todolistName, $expectedSessionKey);

        $this->assertSame($expectedResumeAvailable, $plan->isResumeAvailable(), $comment);
        $this->assertSame($expectedReason, $plan->getReason(), $comment);
        $this->assertSame($expectedStartFrom, $plan->getStartFromTodoIndex(), $comment);
    }

    /**
     * При наличии history_message_count helper выполняет откат истории.
     */
    public function testApplyHistoryRollbackReturnsTrueWhenHistoryPointExists(): void
    {
        $checkpoint = $this->makeCheckpoint()
            ->setTodolistName('step')
            ->setLastCompletedTodoIndex(2)
            ->setHistoryMessageCount(3);
        RunStateCheckpointHelper::write($checkpoint);

        $historyBefore = $this->agentCfg->getChatHistory();
        $plan = TodoListResumeHelper::buildPlan($this->agentCfg, 'step', $this->configApp->getSessionKey());

        $applied = TodoListResumeHelper::applyHistoryRollback($this->agentCfg, $plan);

        $this->assertTrue($applied);
        $this->assertNotSame($historyBefore, $this->agentCfg->getChatHistory());
    }

    /**
     * Без history_message_count helper не делает откат истории.
     */
    public function testApplyHistoryRollbackReturnsFalseWhenHistoryPointMissing(): void
    {
        $checkpoint = $this->makeCheckpoint()
            ->setTodolistName('step')
            ->setLastCompletedTodoIndex(2)
            ->setHistoryMessageCount(null);
        RunStateCheckpointHelper::write($checkpoint);

        $plan = TodoListResumeHelper::buildPlan($this->agentCfg, 'step', $this->configApp->getSessionKey());

        $this->assertFalse(TodoListResumeHelper::applyHistoryRollback($this->agentCfg, $plan));
    }

    /**
     * @return iterable<string, array{0:string,1:?RunStateDto,2:string,3:?string,4:bool,5:string,6:int}>
     */
    public static function provideResumePlanCases(): iterable
    {
        $sessionKey = '20250301-143022-123456-0';
        $makeCheckpoint = static function () use ($sessionKey): RunStateDto {
            return (new RunStateDto())
                ->setSessionKey($sessionKey)
                ->setAgentName(RunStateDto::DEF_AGENT_NAME)
                ->setRunId('run-1')
                ->setStartedAt('2025-01-01T00:00:00+00:00')
                ->setTodolistName('step')
                ->setLastCompletedTodoIndex(-1)
                ->setHistoryMessageCount(0)
                ->setGotoRequestedTodoIndex(null)
                ->setGotoTransitionsCount(0)
                ->setFinished(false);
        };

        yield 'no_checkpoint' => [
            'без checkpoint resume недоступен',
            null,
            'step',
            $sessionKey,
            false,
            'no_checkpoint',
            0,
        ];

        yield 'finished' => [
            'finished checkpoint не даёт resume',
            $makeCheckpoint()->setTodolistName('step')->setFinished(true),
            'step',
            $sessionKey,
            false,
            'finished',
            0,
        ];

        yield 'todolist_mismatch' => [
            'чужой список не даёт resume',
            $makeCheckpoint()->setTodolistName('other'),
            'step',
            $sessionKey,
            false,
            'todolist_mismatch',
            0,
        ];

        yield 'session_mismatch' => [
            'чужой session key приводит к отсутствию подходящего checkpoint-файла',
            $makeCheckpoint()->setTodolistName('step')->setSessionKey('other-session-0'),
            'step',
            $sessionKey,
            false,
            'no_checkpoint',
            0,
        ];

        yield 'ready_from_minus_one' => [
            'после -1 стартуем с нуля',
            $makeCheckpoint()->setTodolistName('step')->setLastCompletedTodoIndex(-1)->setHistoryMessageCount(0),
            'step',
            $sessionKey,
            true,
            'ready',
            0,
        ];

        yield 'ready_from_zero' => [
            'после 0 стартуем с 1',
            $makeCheckpoint()->setTodolistName('step')->setLastCompletedTodoIndex(0)->setHistoryMessageCount(2),
            'step',
            $sessionKey,
            true,
            'ready',
            1,
        ];

        yield 'ready_large_index' => [
            'после 11 стартуем с 12',
            $makeCheckpoint()->setTodolistName('step')->setLastCompletedTodoIndex(11)->setHistoryMessageCount(20),
            'step',
            $sessionKey,
            true,
            'ready',
            12,
        ];

        yield 'history_missing' => [
            'без history_message_count resume доступен, но без rollback',
            $makeCheckpoint()->setTodolistName('step')->setLastCompletedTodoIndex(4)->setHistoryMessageCount(null),
            'step',
            $sessionKey,
            true,
            'history_missing',
            5,
        ];

        yield 'empty_checkpoint_session_is_allowed' => [
            'пустой session_key в checkpoint не даёт найти файл текущей сессии',
            $makeCheckpoint()->setTodolistName('step')->setSessionKey('')->setLastCompletedTodoIndex(1)->setHistoryMessageCount(3),
            'step',
            $sessionKey,
            false,
            'no_checkpoint',
            0,
        ];

        yield 'negative_last_completed_clamped' => [
            'сильно отрицательный last_completed зажимается к нулю',
            $makeCheckpoint()->setTodolistName('step')->setLastCompletedTodoIndex(-50)->setHistoryMessageCount(1),
            'step',
            $sessionKey,
            true,
            'ready',
            0,
        ];
    }

    private function makeCheckpoint(): RunStateDto
    {
        return (new RunStateDto())
            ->setSessionKey($this->configApp->getSessionKey())
            ->setAgentName(RunStateDto::DEF_AGENT_NAME)
            ->setRunId('run-1')
            ->setStartedAt('2025-01-01T00:00:00+00:00')
            ->setTodolistName('step')
            ->setLastCompletedTodoIndex(-1)
            ->setHistoryMessageCount(0)
            ->setGotoRequestedTodoIndex(null)
            ->setGotoTransitionsCount(0)
            ->setFinished(false);
    }

    private function resetConfigurationAppSingleton(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
