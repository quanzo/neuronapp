<?php

declare(strict_types=1);

namespace Tests\Todo;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\todo\TodoList;
use NeuronAI\Chat\Enums\MessageRole;
use PHPUnit\Framework\TestCase;
use Tests\Support\SpyProvider;

/**
 * Тесты выполнения TodoList с командами @@thinking / @@think внутри todo.
 *
 * Проверяем, что режим think переключается только на один пункт списка,
 * перекрывает think из шапки при необходимости и не влияет на следующие пункты.
 */
final class TodoListThinkingExecuteTest extends TestCase
{
    private string $tmpDir;
    private ConfigurationApp $configApp;

    protected function setUp(): void
    {
        SpyProvider::reset();

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_todolist_think_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);
        mkdir($this->tmpDir . '/agents', 0777, true);

        file_put_contents($this->tmpDir . '/config.jsonc', json_encode([
            'context_files' => [
                'enabled' => false,
            ],
        ], JSON_THROW_ON_ERROR));

        $this->resetConfigurationAppSingleton();

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp, 'config.jsonc');
        $this->configApp = ConfigurationApp::getInstance();
        $this->configApp->setSessionKey('test-session');

        $this->writeAgent('default', 'default');
        $this->writeAgent('agent-coder', 'agent-coder');
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        $this->removeDir($this->tmpDir);
    }

    private function resetConfigurationAppSingleton(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $prop = $ref->getProperty('instance');
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

    private function writeAgent(string $agentName, string $label): void
    {
        $path = $this->tmpDir . '/agents/' . $agentName . '.php';
        $code = sprintf(
            '<?php return ["enableChatHistory" => false, "contextWindow" => 50000, "provider" => new \\Tests\\Support\\SpyProvider(%s)];',
            var_export($label, true)
        );
        file_put_contents($path, $code);
    }

    private function makeTodoList(string $input): TodoList
    {
        $list = new TodoList($input, 'list', $this->configApp);
        $baseCfg = $this->configApp->getAgent('default');
        $this->assertInstanceOf(ConfigurationAgent::class, $baseCfg);
        $list->setDefaultConfigurationAgent($baseCfg);

        return $list;
    }

    /**
     * @@thinking без шапки think включает think только на первом пункте.
     */
    public function testExecuteThinkingCmdEnablesThinkForOneTodoOnly(): void
    {
        $list = $this->makeTodoList("1. @@thinking Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame([true, false], array_column(SpyProvider::$calls, 'think'));
    }

    /**
     * Шапка think:true, @@thinking(false) отключает think только на этом пункте.
     */
    public function testExecuteThinkingFalseOverridesHeaderThinkForOneTodo(): void
    {
        $input = "---\nthink: true\n---\n1. @@thinking(false) Hello\n2. World";
        $list = $this->makeTodoList($input);

        $list->execute(MessageRole::USER)->await();

        $this->assertSame([false, true], array_column(SpyProvider::$calls, 'think'));
    }

    /**
     * Несколько @@think / @@thinking в одном todo — побеждает последняя команда.
     */
    public function testExecuteMultipleThinkingCmdUsesLast(): void
    {
        $list = $this->makeTodoList("1. @@thinking(true) @@think(false) Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame([false, false], array_column(SpyProvider::$calls, 'think'));
    }

    /**
     * @@thinking применяется к агенту, переключённому через @@agent.
     */
    public function testExecuteThinkingWithAgentSwitch(): void
    {
        $list = $this->makeTodoList("1. @@agent(\"agent-coder\") @@thinking Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['agent-coder', 'default'], array_column(SpyProvider::$calls, 'label'));
        $this->assertSame([true, false], array_column(SpyProvider::$calls, 'think'));
    }

    /**
     * Сигнатура @@thinking удаляется из текста перед отправкой в LLM.
     */
    public function testExecuteStripsThinkingCmdFromSentText(): void
    {
        $list = $this->makeTodoList("1. @@thinking Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertStringNotContainsString('@@thinking', SpyProvider::$calls[0]['content']);
        $this->assertSame('Hello', SpyProvider::$calls[0]['content']);
    }

    /**
     * startFromTodoIndex: override из пропущенных пунктов не влияет на выполняемые.
     */
    public function testExecuteStartFromTodoIndexDoesNotLeakThinkingFromSkippedTodos(): void
    {
        $list = $this->makeTodoList("1. @@thinking Skip\n2. Hello\n3. World");

        $list->execute(MessageRole::USER, [], null, 1)->await();

        $this->assertSame([false, false], array_column(SpyProvider::$calls, 'think'));
    }

    /**
     * @@think без аргументов включает think на одном пункте (алиас @@thinking).
     */
    public function testExecuteThinkAliasEnablesThinkForOneTodo(): void
    {
        $list = $this->makeTodoList("1. @@think Task\n2. Next");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame([true, false], array_column(SpyProvider::$calls, 'think'));
    }

    /**
     * Некорректный аргумент @@thinking("invalid") — наследуется think из шапки.
     */
    public function testExecuteInvalidThinkingArgInheritsHeaderThink(): void
    {
        $input = "---\nthink: true\n---\n1. @@thinking(\"invalid\") Hello\n2. World";
        $list = $this->makeTodoList($input);

        $list->execute(MessageRole::USER)->await();

        $this->assertSame([true, true], array_column(SpyProvider::$calls, 'think'));
    }

    /**
     * После пункта с @@thinking(false) следующий пункт снова использует think из шапки.
     */
    public function testExecuteThinkingOverrideDoesNotAffectNextTodo(): void
    {
        $input = "---\nthink: true\n---\n1. @@thinking(false) One\n2. Two";
        $list = $this->makeTodoList($input);

        $list->execute(MessageRole::USER)->await();

        $this->assertSame([false, true], array_column(SpyProvider::$calls, 'think'));
    }

    /**
     * Команда @@agent вырезается из текста вместе с @@thinking при новом порядке разбора.
     */
    public function testExecuteStripsAgentAndThinkingFromSentText(): void
    {
        $list = $this->makeTodoList("1. @@agent(\"agent-coder\") @@thinking Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertStringNotContainsString('@@agent', SpyProvider::$calls[0]['content']);
        $this->assertStringNotContainsString('@@thinking', SpyProvider::$calls[0]['content']);
        $this->assertSame('Hello', SpyProvider::$calls[0]['content']);
    }
}
