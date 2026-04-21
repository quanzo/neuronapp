<?php

declare(strict_types=1);

namespace Tests\Todo;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\events\TodoEventDto;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\enums\EventNameEnum;
use NeuronAI\Chat\Enums\MessageRole;
use PHPUnit\Framework\TestCase;
use Tests\Support\TodoGotoSpyProvider;

/**
 * Тесты выполнения `TodoList::execute()` с переходами, запрошенными через `TodoGotoTool`.
 *
 * Переход имитируется тестовым провайдером, который вызывает tool `todo_goto` как будто это сделала LLM.
 */
final class TodoListGotoExecuteTest extends TestCase
{
    private string $tmpDir;
    private ConfigurationApp $configApp;

    protected function setUp(): void
    {
        TodoGotoSpyProvider::reset();
        EventBus::clear();

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_todolist_goto_' . uniqid();
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
        ConfigurationApp::init(new DirPriority([$this->tmpDir]), 'config.jsonc');
        $this->configApp = ConfigurationApp::getInstance();
        $this->configApp->setSessionKey('goto-test-session');

        $this->writeAgent('default');
    }

    protected function tearDown(): void
    {
        EventBus::clear();
        $this->resetConfigurationAppSingleton();
        $this->removeDir($this->tmpDir);
    }

    /**
     * goto с первого шага на пункт 3 (1-based) пропускает второй пункт.
     */
    public function testExecuteGotoForwardSkipsMiddleTodo(): void
    {
        TodoGotoSpyProvider::setGotoPlan([1 => 3]);
        $list = $this->makeTodoList("1. First\n2. Second\n3. Third");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['First', 'Third'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * goto на несуществующий пункт завершает цикл после текущего шага.
     */
    public function testExecuteGotoOutOfRangeStopsExecution(): void
    {
        TodoGotoSpyProvider::setGotoPlan([1 => 99]);
        $list = $this->makeTodoList("1. First\n2. Second\n3. Third");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['First'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * goto назад (со второго на первый) повторно выполняет первые шаги и затем продолжает цикл.
     */
    public function testExecuteGotoBackwardRepeatsBlockAndContinues(): void
    {
        TodoGotoSpyProvider::setGotoPlan([2 => 1]);
        $list = $this->makeTodoList("1. First\n2. Second\n3. Third");

        $list->execute(MessageRole::USER)->await();

        $this->assertSame(
            ['First', 'Second', 'Third'],
            array_column(TodoGotoSpyProvider::$calls, 'content')
        );
    }

    /**
     * При циклическом goto выполнение останавливается по лимиту переходов.
     */
    public function testExecuteStopsByGotoTransitionsLimit(): void
    {
        TodoGotoSpyProvider::setGotoPlan(array_fill(1, 120, 1));
        $list = $this->makeTodoList("1. First\n2. Second");

        $list->execute(MessageRole::USER)->await();

        $this->assertCount(2, TodoGotoSpyProvider::$calls);
        $this->assertSame('First', TodoGotoSpyProvider::$calls[0]['content']);
        $this->assertSame('Second', TodoGotoSpyProvider::$calls[1]['content']);
    }

    /**
     * Граничный случай: startFromTodoIndex применяется до goto и стартует с нужного пункта.
     */
    public function testStartFromTodoIndexWithGoto(): void
    {
        TodoGotoSpyProvider::setGotoPlan([1 => 1]);
        $list = $this->makeTodoList("1. First\n2. Second\n3. Third");

        $list->execute(MessageRole::USER, [], null, 1)->await();

        $this->assertSame(
            ['Second', 'Third'],
            array_slice(array_column(TodoGotoSpyProvider::$calls, 'content'), 0, 2)
        );
    }

    /**
     * execute() публикует todo.started/todo.completed и run.finished.
     */
    public function testExecuteEmitsTodoLifecycleEvents(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $events = [];
        EventBus::on(EventNameEnum::TODO_STARTED->value, static function (mixed $payload) use (&$events): void {
            if ($payload instanceof TodoEventDto) {
                $events[] = EventNameEnum::TODO_STARTED->value;
            }
        }, '*');
        EventBus::on(EventNameEnum::TODO_COMPLETED->value, static function (mixed $payload) use (&$events): void {
            if ($payload instanceof TodoEventDto) {
                $events[] = EventNameEnum::TODO_COMPLETED->value;
            }
        }, '*');
        EventBus::on(EventNameEnum::RUN_FINISHED->value, static function (mixed $payload) use (&$events): void {
            $events[] = EventNameEnum::RUN_FINISHED->value;
        }, '*');

        $list = $this->makeTodoList("1. First\n2. Second");
        $list->execute(MessageRole::USER)->await();

        $this->assertContains(EventNameEnum::TODO_STARTED->value, $events);
        $this->assertContains(EventNameEnum::TODO_COMPLETED->value, $events);
        $this->assertContains(EventNameEnum::RUN_FINISHED->value, $events);
    }

    /**
     * Создаёт тестовый файл конфигурации агента.
     */
    private function writeAgent(string $agentName): void
    {
        $path = $this->tmpDir . '/agents/' . $agentName . '.php';
        $code = '<?php return ["enableChatHistory" => true, "contextWindow" => 50000, "provider" => new \\Tests\\Support\\TodoGotoSpyProvider(), "tools" => [[\\app\\modules\\neuron\\tools\\TodoGotoTool::class, "make"]]];';
        file_put_contents($path, $code);
    }

    /**
     * Создаёт TodoList с подключенным агентом default.
     */
    private function makeTodoList(string $input): TodoList
    {
        $list = new TodoList($input, 'list', $this->configApp);
        $baseCfg = $this->configApp->getAgent('default');
        $this->assertInstanceOf(ConfigurationAgent::class, $baseCfg);
        $list->setDefaultConfigurationAgent($baseCfg);

        return $list;
    }

    /**
     * Сбрасывает singleton ConfigurationApp.
     */
    private function resetConfigurationAppSingleton(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);
    }

    /**
     * Рекурсивно удаляет временный каталог.
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
