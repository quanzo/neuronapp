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
use TypeError;

/**
 * Тесты выполнения TodoList с поддержкой команды @@agent("...") внутри todo.
 *
 * Проверяем, что:
 * - переключение агента действует только на один todo и не портит агента цикла;
 * - команда удаляется из отправляемого текста;
 * - при ошибках (не найден агент / неверные аргументы) используется fallback на текущего агента;
 * - при нескольких @@agent(...) выбирается последнее вхождение.
 */
final class TodoListAgentSwitchExecuteTest extends TestCase
{
    private string $tmpDir;
    private ConfigurationApp $configApp;

    protected function setUp(): void
    {
        SpyProvider::reset();

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_todolist_agent_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);
        mkdir($this->tmpDir . '/agents', 0777, true);
        mkdir($this->tmpDir . '/docs', 0777, true);

        file_put_contents($this->tmpDir . '/config.jsonc', json_encode([
            'context_files' => [
                'enabled' => true,
                'max_total_size' => 1048576,
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
     * Без @@agent todo выполняется базовым агентом.
     */
    public function testExecuteWithoutAgentCmdUsesBaseAgent(): void
    {
        $list = $this->makeTodoList("1. Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['default', 'default'], array_column(SpyProvider::$calls, 'label'));
    }

    /**
     * @@agent("agent-coder") переключает выполнение одного todo на agent-coder.
     */
    public function testExecuteWithAgentCmdSwitchesAgentForOneTodo(): void
    {
        $list = $this->makeTodoList("1. @@agent(\"agent-coder\") Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['agent-coder', 'default'], array_column(SpyProvider::$calls, 'label'));
    }

    /**
     * Команда @@agent(...) вырезается из текста перед отправкой в LLM.
     */
    public function testExecuteStripsAgentCmdSignatureFromSentText(): void
    {
        $list = $this->makeTodoList("1. @@agent(\"agent-coder\") Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertStringContainsString('@@agent', SpyProvider::$calls[0]['content']);
        $this->assertSame('@@agent("agent-coder") Hello', SpyProvider::$calls[0]['content']);
    }

    /**
     * Если агент не найден, используем fallback на базовый агент.
     */
    public function testExecuteUnknownAgentFallsBackToBase(): void
    {
        $list = $this->makeTodoList("1. @@agent(\"missing\") Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['default', 'default'], array_column(SpyProvider::$calls, 'label'));
    }

    /**
     * Некорректный тип аргумента @@agent(123) — fallback на базовый агент.
     */
    public function testExecuteAgentCmdWithNonStringArgumentFallsBack(): void
    {
        $this->expectException(TypeError::class);
        $list = $this->makeTodoList("1. @@agent(123) Hello\n2. World");
        $list->execute(MessageRole::USER)->await();
    }

    /**
     * Пустое имя агента @@agent(\"\") — fallback на базовый агент.
     */
    public function testExecuteAgentCmdWithEmptyStringFallsBack(): void
    {
        $this->expectException(TypeError::class);
        $list = $this->makeTodoList("1. @@agent(\"\") Hello\n2. World");
        $list->execute(MessageRole::USER)->await();
    }

    /**
     * Несколько @@agent(...) в одном todo — выбирается последнее.
     */
    public function testExecuteMultipleAgentCmdUsesLast(): void
    {
        $list = $this->makeTodoList("1. @@agent(\"default\") @@agent(\"agent-coder\") Hello\n2. World");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['agent-coder', 'default'], array_column(SpyProvider::$calls, 'label'));
        $this->assertStringContainsString('@@agent', SpyProvider::$calls[0]['content']);
    }

    /**
     * startFromTodoIndex пропускает первые todo: команды из пропущенных не влияют на выполняемые.
     */
    public function testExecuteStartFromTodoIndexDoesNotLeakAgentFromSkippedTodos(): void
    {
        $list = $this->makeTodoList("1. @@agent(\"agent-coder\") Skip\n2. Hello\n3. World");

        $list->execute(MessageRole::USER, [], null, 1)->await();

        $this->assertSame(['default', 'default'], array_column(SpyProvider::$calls, 'label'));
    }

    /**
     * Два todo подряд: первый с переключением, второй без — второй выполняется базовым агентом.
     */
    public function testExecuteAgentSwitchDoesNotAffectNextTodo(): void
    {
        $list = $this->makeTodoList("1. @@agent(\"agent-coder\") One\n2. Two\n3. Three");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['agent-coder', 'default', 'default'], array_column(SpyProvider::$calls, 'label'));
    }

    /**
     * Команда @@agent(...) не ломает сбор @-вложений: файл остаётся в тексте и отправляется как часть сообщения.
     */
    public function testExecuteAgentCmdDoesNotBreakContextFileSyntax(): void
    {
        file_put_contents($this->tmpDir . '/docs/a.txt', 'A');

        $list = $this->makeTodoList("1. @@agent(\"agent-coder\") Read @docs/a.txt");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['agent-coder'], array_column(SpyProvider::$calls, 'label'));
        $this->assertStringContainsString('@docs/a.txt', SpyProvider::$calls[0]['content']);
    }
}
