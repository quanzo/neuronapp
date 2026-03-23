<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\run\RunStateDto;
use app\modules\neuron\helpers\RunStateCheckpointHelper;
use app\modules\neuron\tools\TodoGotoTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see TodoGotoTool}.
 *
 * Проверяют корректность валидации входных данных, запись запроса перехода в run-state
 * и сериализацию результата для LLM.
 */
final class TodoGotoToolTest extends TestCase
{
    private string $tmpDir;
    private ConfigurationAgent $agentCfg;
    private TodoGotoTool $tool;
    private string $sessionKey = '20260101-120000-1';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_todo_goto_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);
        mkdir($this->tmpDir . '/agents', 0777, true);

        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]));
        ConfigurationApp::getInstance()->setSessionKey($this->sessionKey);

        $this->agentCfg = new ConfigurationAgent();
        $this->agentCfg->setConfigurationApp(ConfigurationApp::getInstance());
        $this->agentCfg->setSessionKey($this->sessionKey);

        $this->tool = new TodoGotoTool();
        $this->tool->setAgentCfg($this->agentCfg);
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * Невалидный point = 0 отклоняется.
     */
    public function testRejectsZeroTargetPoint(): void
    {
        $data = json_decode(($this->tool)(0), true);
        $this->assertFalse($data['success']);
    }

    /**
     * Невалидный отрицательный point отклоняется.
     */
    public function testRejectsNegativeTargetPoint(): void
    {
        $data = json_decode(($this->tool)(-2), true);
        $this->assertFalse($data['success']);
    }

    /**
     * Без активного run-state переход не выполняется.
     */
    public function testFailsWithoutRunState(): void
    {
        $data = json_decode(($this->tool)(1), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('run-state', $data['message']);
    }

    /**
     * Валидный point записывает goto-запрос в checkpoint.
     */
    public function testWritesGotoRequestedIndex(): void
    {
        $this->writeRunState(lastCompletedIndex: 0);

        $data = json_decode(($this->tool)(3, 'вернуться к шагу проверки'), true);
        $this->assertTrue($data['success']);
        $this->assertSame(3, $data['toPoint']);

        $state = RunStateCheckpointHelper::read($this->sessionKey);
        $this->assertInstanceOf(RunStateDto::class, $state);
        $this->assertSame(2, $state->getGotoRequestedTodoIndex());
    }

    /**
     * fromPoint рассчитывается как lastCompletedTodoIndex + 1.
     */
    public function testResponseContainsFromPoint(): void
    {
        $this->writeRunState(lastCompletedIndex: 4);

        $data = json_decode(($this->tool)(2), true);
        $this->assertTrue($data['success']);
        $this->assertSame(5, $data['fromPoint']);
    }

    /**
     * Пустой reason нормализуется в null.
     */
    public function testEmptyReasonBecomesNull(): void
    {
        $this->writeRunState(lastCompletedIndex: 1);

        $data = json_decode(($this->tool)(1, '   '), true);
        $this->assertTrue($data['success']);
        $this->assertNull($data['reason']);
    }

    /**
     * Повторный вызов перезаписывает прошлый goto-запрос.
     */
    public function testSecondCallOverridesRequestedIndex(): void
    {
        $this->writeRunState(lastCompletedIndex: 1);

        ($this->tool)(2);
        ($this->tool)(4);

        $state = RunStateCheckpointHelper::read($this->sessionKey);
        $this->assertInstanceOf(RunStateDto::class, $state);
        $this->assertSame(3, $state->getGotoRequestedTodoIndex());
    }

    /**
     * Инструмент не изменяет счётчик уже применённых goto-переходов.
     */
    public function testDoesNotChangeGotoTransitionsCount(): void
    {
        $this->writeRunState(lastCompletedIndex: 2, gotoTransitionsCount: 7);

        ($this->tool)(1);

        $state = RunStateCheckpointHelper::read($this->sessionKey);
        $this->assertInstanceOf(RunStateDto::class, $state);
        $this->assertSame(7, $state->getGotoTransitionsCount());
    }

    /**
     * Граничный случай: point = 1 возвращается в ответе как toPoint=1.
     */
    public function testFirstPointIsReturnedAsOneBasedPoint(): void
    {
        $this->writeRunState(lastCompletedIndex: 0);

        $data = json_decode(($this->tool)(1), true);
        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['toPoint']);
    }

    /**
     * Граничный случай: большой номер пункта возвращается как есть (1-based),
     * проверка диапазона делегирована TodoList.
     */
    public function testLargeTargetPointIsStoredForTodoListValidation(): void
    {
        $this->writeRunState(lastCompletedIndex: 0);

        $data = json_decode(($this->tool)(999), true);
        $this->assertTrue($data['success']);
        $this->assertSame(999, $data['toPoint']);
    }

    /**
     * Создаёт run-state для теста инструмента.
     */
    private function writeRunState(int $lastCompletedIndex, int $gotoTransitionsCount = 0): void
    {
        $dto = (new RunStateDto())
            ->setSessionKey($this->sessionKey)
            ->setAgentName(RunStateDto::DEF_AGENT_NAME)
            ->setRunId('run-1')
            ->setTodolistName('list')
            ->setStartedAt('2026-01-01T12:00:00+00:00')
            ->setLastCompletedTodoIndex($lastCompletedIndex)
            ->setHistoryMessageCount(0)
            ->setGotoRequestedTodoIndex(null)
            ->setGotoTransitionsCount($gotoTransitionsCount)
            ->setFinished(false);
        RunStateCheckpointHelper::write($dto);
    }

    /**
     * Сбрасывает singleton ConfigurationApp между тестами.
     */
    private function resetConfigurationAppSingleton(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * Рекурсивно удаляет временную директорию тестов.
     */
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
