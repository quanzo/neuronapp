<?php

declare(strict_types=1);

namespace Tests\Todo;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\helpers\LlmCycleHelper;
use NeuronAI\Chat\Enums\MessageRole;
use PHPUnit\Framework\TestCase;
use Tests\Support\TodoGotoSpyProvider;

/**
 * Тесты {@see TodoList::execute()} с параметром мягкого продолжения `softContinue`.
 *
 * {@see TodoGotoSpyProvider} фиксирует только пользовательские тексты пунктов списка,
 * не служебные реплики {@see LlmCycleHelper::waitCycle()}.
 */
final class TodoListSoftContinueExecuteTest extends TestCase
{
    private string $tmpDir;
    private ConfigurationApp $configApp;

    protected function setUp(): void
    {
        TodoGotoSpyProvider::reset();

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_softcontinue_' . uniqid();
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
        $this->configApp->setSessionKey('softcontinue-session');

        $this->writeAgent('default');
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        $this->removeDir($this->tmpDir);
    }

    /**
     * При softContinue=true тело первого пункта не отправляется повторно, второй пункт уходит в LLM.
     */
    public function testSoftContinueTrueSkipsOnlyFirstTodoBodyThenSendsSecond(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. First\n2. Second");
        $list->execute(MessageRole::USER, [], null, 0, null, true)->await();

        $this->assertSame(['Second'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * При softContinue=false оба текста todo доставляются провайдеру.
     */
    public function testSoftContinueFalseSendsBothTodoBodies(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. First\n2. Second");
        $list->execute(MessageRole::USER, [], null, 0, null, false)->await();

        $this->assertSame(['First', 'Second'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * Значение по умолчанию softContinue=null ведёт себя как обычный прогон без мягкого продолжения.
     */
    public function testSoftContinueNullDefaultSendsAllTodoBodies(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. Alpha\n2. Beta");
        $list->execute(MessageRole::USER)->await();

        $this->assertSame(['Alpha', 'Beta'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * Мягкий пропуск только для индекса startFromTodoIndex: при старте с 1 не отправляется «B», «C» отправляется.
     */
    public function testSoftContinueTrueSkipsOnlyNormalizedStartIndex(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. A\n2. B\n3. C");
        $list->execute(MessageRole::USER, [], null, 1, null, true)->await();

        $this->assertSame(['C'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * Отрицательный startFromTodoIndex нормализуется к 0; softContinue пропускает первый пункт как при старте с нуля.
     */
    public function testSoftContinueWithNegativeStartNormalizesToZero(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. X\n2. Y");
        $list->execute(MessageRole::USER, [], null, -5, null, true)->await();

        $this->assertSame(['Y'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * Один пункт и softContinue: в шпион не попадает пользовательский текст todo (остаётся только waitCycle).
     */
    public function testSoftContinueSingleTodoRecordsNoTodoContentInSpy(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. Solo");
        $list->execute(MessageRole::USER, [], null, 0, null, true)->await();

        $this->assertSame([], TodoGotoSpyProvider::$calls);
    }

    /**
     * Три пункта: пропуск только первого тела, «Two» и «Three» отправляются.
     */
    public function testSoftContinueTrueThreeTodosSendsBodiesForSecondAndThird(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. One\n2. Two\n3. Three");
        $list->execute(MessageRole::USER, [], null, 0, null, true)->await();

        $this->assertSame(['Two', 'Three'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * Старт с последнего индекса и softContinue: единственный оставшийся пункт без отправки тела.
     */
    public function testSoftContinueTrueStartFromLastIndexSkipsOnlyThatBody(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. P1\n2. P2\n3. P3");
        $list->execute(MessageRole::USER, [], null, 2, null, true)->await();

        $this->assertSame([], TodoGotoSpyProvider::$calls);
    }

    /**
     * Без softContinue при startFromTodoIndex=1 оба оставшихся пункта отправляются целиком.
     */
    public function testStartFromOneWithoutSoftContinueSendsBothRemainingBodies(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. A\n2. B\n3. C");
        $list->execute(MessageRole::USER, [], null, 1, null, null)->await();

        $this->assertSame(['B', 'C'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * Явный softContinue=false на индексе resume не отличается от обычной отправки этого пункта.
     */
    public function testSoftContinueFalseAtResumeIndexSendsThatTodoBody(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList("1. M\n2. N");
        $list->execute(MessageRole::USER, [], null, 1, null, false)->await();

        $this->assertSame(['N'], array_column(TodoGotoSpyProvider::$calls, 'content'));
    }

    /**
     * Граничный случай: заведомо «ломаный» softContinue=true при пустом списке — выполнение завершается без вызовов провайдера по todo-текстам.
     */
    public function testSoftContinueTrueOnEmptyTodoListYieldsNoProviderTodoCalls(): void
    {
        TodoGotoSpyProvider::setGotoPlan([]);
        $list = $this->makeTodoList('');
        $list->execute(MessageRole::USER, [], null, 0, null, true)->await();

        $this->assertSame([], TodoGotoSpyProvider::$calls);
    }

    private function writeAgent(string $agentName): void
    {
        $path = $this->tmpDir . '/agents/' . $agentName . '.php';
        $code = '<?php return ["enableChatHistory" => true, "contextWindow" => 50000, "provider" => new \\Tests\\Support\\TodoGotoSpyProvider(), "tools" => [[\\app\\modules\\neuron\\tools\\TodoGotoTool::class, "make"]]];';
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
}
