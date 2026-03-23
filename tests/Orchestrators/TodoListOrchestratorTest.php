<?php

declare(strict_types=1);

namespace Tests\Orchestrators;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\orchestrators\TodoListOrchestrator;
use app\modules\neuron\classes\todo\TodoList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestableTodoListOrchestrator;
use Tests\Support\OrchestratorSpyProvider;

/**
 * Тесты внешнего оркестратора {@see TodoListOrchestrator}.
 *
 * Проверяют:
 * - успешное завершение и вызов onComplete;
 * - завершение по лимиту итераций и вызов onFail;
 * - рестарт после ошибки;
 * - поведение без рестарта;
 * - нормализацию completed на расширенном наборе данных (>=10).
 */
final class TodoListOrchestratorTest extends TestCase
{
    private string $tmpDir;
    private ConfigurationApp $configApp;
    private TodoList $init;
    private TodoList $step;
    private TodoList $finish;

    protected function setUp(): void
    {
        OrchestratorSpyProvider::reset();

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_orchestrator_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);
        mkdir($this->tmpDir . '/agents', 0777, true);
        mkdir($this->tmpDir . '/todos', 0777, true);

        file_put_contents($this->tmpDir . '/config.jsonc', '{}');
        file_put_contents(
            $this->tmpDir . '/agents/default.php',
            '<?php return [
                "enableChatHistory" => true,
                "provider" => new \\Tests\\Support\\OrchestratorSpyProvider(),
                "tools" => [[\\app\\modules\\neuron\\tools\\TodoCompletedTool::class, "make"]]
            ];'
        );
        file_put_contents($this->tmpDir . '/todos/init.md', "1. INIT\n");
        file_put_contents($this->tmpDir . '/todos/step.md', "1. STEP\n");
        file_put_contents($this->tmpDir . '/todos/finish.md', "1. FINISH\n");

        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]), 'config.jsonc');
        $this->configApp = ConfigurationApp::getInstance();
        $this->configApp->setSessionKey('20250101-120000-1-0');

        $agent = $this->configApp->getAgent('default');
        $this->assertNotNull($agent);

        $this->init = $this->configApp->getTodoList('init');
        $this->step = $this->configApp->getTodoList('step');
        $this->finish = $this->configApp->getTodoList('finish');
        $this->assertNotNull($this->init);
        $this->assertNotNull($this->step);
        $this->assertNotNull($this->finish);

        $this->init->setDefaultConfigurationAgent($agent);
        $this->step->setDefaultConfigurationAgent($agent);
        $this->finish->setDefaultConfigurationAgent($agent);
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * При completed=1 в пределах лимита цикл завершается успешно и вызывает onComplete.
     */
    public function testRunCompletedCallsOnComplete(): void
    {
        OrchestratorSpyProvider::setCompleteOnStepCall(3);

        $orchestrator = new TestableTodoListOrchestrator($this->configApp, null, false);
        $result = $orchestrator->run($this->init, $this->step, $this->finish, 10, false, 0);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('completed', $result->getReason());
        $this->assertSame(3, $result->getIterations());
        $this->assertSame(1, $orchestrator->completeCalls);
        $this->assertSame(0, $orchestrator->failCalls);
    }

    /**
     * При исчерпании лимита итераций возвращается fail-результат и вызывается onFail.
     */
    public function testRunMaxIterationsCallsOnFail(): void
    {
        OrchestratorSpyProvider::setCompleteOnStepCall(1000);

        $orchestrator = new TestableTodoListOrchestrator($this->configApp, null, false);
        $result = $orchestrator->run($this->init, $this->step, $this->finish, 2, false, 0);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('max_iterations', $result->getReason());
        $this->assertSame(2, $result->getIterations());
        $this->assertSame(0, $orchestrator->completeCalls);
        $this->assertSame(1, $orchestrator->failCalls);
    }

    /**
     * При ошибке step и включенном restart оркестратор перезапускает цикл.
     */
    public function testRunRestartsOnFailWhenEnabled(): void
    {
        OrchestratorSpyProvider::setFailOnStepCalls([1]);
        OrchestratorSpyProvider::setCompleteOnStepCall(2);

        $orchestrator = new TestableTodoListOrchestrator($this->configApp, null, false);
        $result = $orchestrator->run($this->init, $this->step, $this->finish, 5, true, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $result->getRestartCount());
        $this->assertSame('completed', $result->getReason());
        $this->assertSame(1, $orchestrator->completeCalls);
        $this->assertSame(1, $orchestrator->failCalls);
    }

    /**
     * Без restart ошибка шага пробрасывается наружу.
     */
    public function testRunThrowsWithoutRestart(): void
    {
        OrchestratorSpyProvider::setFailOnStepCalls([1]);
        OrchestratorSpyProvider::setCompleteOnStepCall(2);

        $orchestrator = new TestableTodoListOrchestrator($this->configApp, null, false);

        $this->expectException(\RuntimeException::class);
        $orchestrator->run($this->init, $this->step, $this->finish, 5, false, 0);
    }

    /**
     * Проверка нормализации completed на широком наборе входных значений.
     */
    #[DataProvider('provideCompletedNormalizationCases')]
    public function testNormalizeCompleted(mixed $raw, ?int $expected): void
    {
        $orchestrator = new TestableTodoListOrchestrator($this->configApp, null, false);
        $this->assertSame($expected, $orchestrator->normalizeProxy($raw));
    }

    /**
     * Набор данных >=10, включая граничные и невалидные значения.
     *
     * @return array<string,array{0:mixed,1:?int}>
     */
    public static function provideCompletedNormalizationCases(): array
    {
        return [
            'int_1' => [1, 1],
            'int_0' => [0, 0],
            'bool_true' => [true, 1],
            'bool_false' => [false, 0],
            'str_done' => ['done', 1],
            'str_not_done' => ['not_done', 0],
            'str_ru_done' => ['исполнено', 1],
            'str_ru_not_done' => ['не исполнено', 0],
            'str_true' => ['true', 1],
            'str_false' => ['false', 0],
            'str_unknown' => ['unknown', null],
            'null_case' => [null, null],
        ];
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
