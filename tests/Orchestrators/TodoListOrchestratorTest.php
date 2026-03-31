<?php

declare(strict_types=1);

namespace Tests\Orchestrators;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\events\OrchestratorEventDto;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\run\RunStateDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\classes\orchestrators\TodoListOrchestrator;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\helpers\RunStateCheckpointHelper;
use NeuronAI\Chat\Enums\MessageRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestableTodoListOrchestrator;
use Tests\Support\OrchestratorSpyProvider;
use Tests\Support\SummarySpyProvider;

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
        EventBus::clear();

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
        EventBus::clear();
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

        $orchestrator = new TestableTodoListOrchestrator($this->configApp);
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

        $orchestrator = new TestableTodoListOrchestrator($this->configApp);
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
        // Пять провалов подряд — по одному на каждую попытку WaitSuccess в sendMessage (maxLlmAttempts=5);
        // иначе на 4-м вызове chat() stepCalls=4 уже не в списке сбоев и LLM «успешно» отвечает без исключения.
        OrchestratorSpyProvider::setFailOnStepCalls([1, 2, 3, 4, 5]);
        OrchestratorSpyProvider::setCompleteOnStepCall(2);

        $orchestrator = new TestableTodoListOrchestrator($this->configApp);
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
        OrchestratorSpyProvider::setFailOnStepCalls([1, 2, 3, 4, 5]);
        OrchestratorSpyProvider::setCompleteOnStepCall(2);

        $orchestrator = new TestableTodoListOrchestrator($this->configApp);

        $this->expectException(\Error::class);
        $orchestrator->run($this->init, $this->step, $this->finish, 5, false, 0);
    }

    /**
     * Оркестратор публикует события цикла, шагов и успешного завершения.
     */
    public function testRunEmitsOrchestratorLifecycleEvents(): void
    {
        OrchestratorSpyProvider::setCompleteOnStepCall(2);

        $events = [];
        EventBus::on(EventNameEnum::ORCHESTRATOR_CYCLE_STARTED->value, static function (mixed $payload) use (&$events): void {
            if ($payload instanceof OrchestratorEventDto) {
                $events[] = EventNameEnum::ORCHESTRATOR_CYCLE_STARTED->value;
            }
        }, '*');
        EventBus::on(EventNameEnum::ORCHESTRATOR_STEP_COMPLETED->value, static function (mixed $payload) use (&$events): void {
            if ($payload instanceof OrchestratorEventDto) {
                $events[] = EventNameEnum::ORCHESTRATOR_STEP_COMPLETED->value;
            }
        }, '*');
        EventBus::on(EventNameEnum::ORCHESTRATOR_COMPLETED->value, static function (mixed $payload) use (&$events): void {
            if ($payload instanceof OrchestratorEventDto) {
                $events[] = EventNameEnum::ORCHESTRATOR_COMPLETED->value;
            }
        }, '*');

        $orchestrator = new TestableTodoListOrchestrator($this->configApp);
        $result = $orchestrator->run($this->init, $this->step, $this->finish, 5, false, 0);

        $this->assertTrue($result->isSuccess());
        $this->assertContains(EventNameEnum::ORCHESTRATOR_CYCLE_STARTED->value, $events);
        $this->assertContains(EventNameEnum::ORCHESTRATOR_STEP_COMPLETED->value, $events);
        $this->assertContains(EventNameEnum::ORCHESTRATOR_COMPLETED->value, $events);
    }

    /**
     * При включенной опции суммаризации step-истории оркестратор суммаризирует каждый шаг step-цикла.
     */
    public function testStepHistorySummarizationReplacesHistoryWithSummary(): void
    {
        // Пересоздаём конфигурацию, чтобы включить фичу через config.jsonc.
        $config = [
            'orchestrator' => [
                'step_history_summarize' => [
                    'enabled' => true,
                    'skill' => 'summarize/step_history',
                    'use_skill' => true,
                    'min_transcript_chars' => 0,
                ],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config.jsonc',
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Добавляем отдельного агента под skill, чтобы вызов суммаризации не влиял на step/init/finish сценарии.
        file_put_contents(
            $this->tmpDir . '/agents/summarizer.php',
            '<?php return [
                "enableChatHistory" => true,
                "provider" => new \\Tests\\Support\\SummarySpyProvider(),
                "tools" => []
            ];'
        );

        // Создаём skill, который будет выполнен после step-цикла.
        mkdir($this->tmpDir . '/skills', 0777, true);
        mkdir($this->tmpDir . '/skills/summarize', 0777, true);
        file_put_contents(
            $this->tmpDir . '/skills/summarize/step_history.md',
            "-----\n"
            . "description: \"Summarize step history\"\n"
            . "params: {\"transcript\": {\"type\": \"string\", \"description\": \"Transcript\", \"required\": true}}\n"
            . "agent: summarizer\n"
            . "pure_context: 1\n"
            . "-----\n"
            . "Summarize this transcript:\n"
            . '"$transcript"' . "\n"
        );

        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]), 'config.jsonc');
        $configApp = ConfigurationApp::getInstance();
        $configApp->setSessionKey('20250101-120000-1-0');

        $agent = $configApp->getAgent('default');
        $this->assertNotNull($agent);

        $init = $configApp->getTodoList('init');
        $step = $configApp->getTodoList('step');
        $finish = $configApp->getTodoList('finish');
        $this->assertNotNull($init);
        $this->assertNotNull($step);
        $this->assertNotNull($finish);

        $init->setDefaultConfigurationAgent($agent);
        $step->setDefaultConfigurationAgent($agent);
        $finish->setDefaultConfigurationAgent($agent);

        OrchestratorSpyProvider::setCompleteOnStepCall(2);

        $orchestrator = new TestableTodoListOrchestrator($configApp);
        $result = $orchestrator->run($init, $step, $finish, 5, false, 0);
        $this->assertTrue($result->isSuccess());

        $history = $agent->getChatHistory();
        $messages = method_exists($history, 'getFullMessages') ? $history->getFullMessages() : $history->getMessages();
        $contents = array_map(static fn ($m) => (string) ($m->getContent() ?? ''), $messages);

        // init + 2 итерации step + finish: init/finish остаются, каждый step-диапазон заменяется одним "SUMMARY".
        $this->assertSame(2, substr_count(implode("\n", $contents), 'SUMMARY'));
    }

    /**
     * Если transcript слишком короткий (min_transcript_chars), суммаризация шага пропускается.
     */
    public function testStepHistorySummarizationSkipsWhenTranscriptTooShort(): void
    {
        $config = [
            'orchestrator' => [
                'step_history_summarize' => [
                    'enabled' => true,
                    'skill' => 'summarize/step_history',
                    'use_skill' => true,
                    'min_transcript_chars' => 100000,
                ],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config.jsonc',
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        file_put_contents(
            $this->tmpDir . '/agents/summarizer.php',
            '<?php return [
                "enableChatHistory" => true,
                "provider" => new \\Tests\\Support\\SummarySpyProvider(),
                "tools" => []
            ];'
        );

        mkdir($this->tmpDir . '/skills', 0777, true);
        mkdir($this->tmpDir . '/skills/summarize', 0777, true);
        file_put_contents(
            $this->tmpDir . '/skills/summarize/step_history.md',
            "-----\n"
            . "description: \"Summarize step history\"\n"
            . "params: {\"transcript\": {\"type\": \"string\", \"description\": \"Transcript\", \"required\": true}}\n"
            . "agent: summarizer\n"
            . "pure_context: 1\n"
            . "-----\n"
            . "Summarize this transcript:\n"
            . '"$transcript"' . "\n"
        );

        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]), 'config.jsonc');
        $configApp = ConfigurationApp::getInstance();
        $configApp->setSessionKey('20250101-120000-1-0');

        $agent = $configApp->getAgent('default');
        $this->assertNotNull($agent);

        $init = $configApp->getTodoList('init');
        $step = $configApp->getTodoList('step');
        $finish = $configApp->getTodoList('finish');
        $this->assertNotNull($init);
        $this->assertNotNull($step);
        $this->assertNotNull($finish);

        $init->setDefaultConfigurationAgent($agent);
        $step->setDefaultConfigurationAgent($agent);
        $finish->setDefaultConfigurationAgent($agent);

        OrchestratorSpyProvider::setCompleteOnStepCall(2);

        $orchestrator = new TestableTodoListOrchestrator($configApp);
        $result = $orchestrator->run($init, $step, $finish, 5, false, 0);
        $this->assertTrue($result->isSuccess());

        $history = $agent->getChatHistory();
        $messages = method_exists($history, 'getFullMessages') ? $history->getFullMessages() : $history->getMessages();
        $contents = array_map(static fn ($m) => (string) ($m->getContent() ?? ''), $messages);
        $this->assertSame(0, substr_count(implode("\n", $contents), 'SUMMARY'));
    }

    /**
     * В режиме append_summary summary добавляется отдельным сообщением; роль summary берётся из конфигурации.
     */
    public function testStepHistorySummarizationAppendSummaryWithSystemRole(): void
    {
        $config = [
            'orchestrator' => [
                'step_history_summarize' => [
                    'enabled' => true,
                    'skill' => 'summarize/step_history',
                    'use_skill' => true,
                    'mode' => 'append_summary',
                    'role' => 'system',
                    'min_transcript_chars' => 0,
                ],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config.jsonc',
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        file_put_contents(
            $this->tmpDir . '/agents/summarizer.php',
            '<?php return [
                "enableChatHistory" => true,
                "provider" => new \\Tests\\Support\\SummarySpyProvider(),
                "tools" => []
            ];'
        );

        mkdir($this->tmpDir . '/skills', 0777, true);
        mkdir($this->tmpDir . '/skills/summarize', 0777, true);
        file_put_contents(
            $this->tmpDir . '/skills/summarize/step_history.md',
            "-----\n"
            . "description: \"Summarize step history\"\n"
            . "params: {\"transcript\": {\"type\": \"string\", \"description\": \"Transcript\", \"required\": true}}\n"
            . "agent: summarizer\n"
            . "pure_context: 1\n"
            . "-----\n"
            . "Summarize this transcript:\n"
            . '"$transcript"' . "\n"
        );

        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]), 'config.jsonc');
        $configApp = ConfigurationApp::getInstance();
        $configApp->setSessionKey('20250101-120000-1-0');

        $agent = $configApp->getAgent('default');
        $this->assertNotNull($agent);

        $init = $configApp->getTodoList('init');
        $step = $configApp->getTodoList('step');
        $finish = $configApp->getTodoList('finish');
        $this->assertNotNull($init);
        $this->assertNotNull($step);
        $this->assertNotNull($finish);

        $init->setDefaultConfigurationAgent($agent);
        $step->setDefaultConfigurationAgent($agent);
        $finish->setDefaultConfigurationAgent($agent);

        OrchestratorSpyProvider::setCompleteOnStepCall(2);

        $orchestrator = new TestableTodoListOrchestrator($configApp);
        $result = $orchestrator->run($init, $step, $finish, 5, false, 0);
        $this->assertTrue($result->isSuccess());

        $history = $agent->getChatHistory();
        $messages = method_exists($history, 'getFullMessages') ? $history->getFullMessages() : $history->getMessages();

        $summaryMessages = array_values(array_filter($messages, static fn ($m) => (string) ($m->getContent() ?? '') === 'SUMMARY'));
        $this->assertCount(2, $summaryMessages);
        $this->assertSame(MessageRole::SYSTEM->value, (string) $summaryMessages[0]->getRole());
        $this->assertSame(MessageRole::SYSTEM->value, (string) $summaryMessages[1]->getRole());
    }

    /**
     * При use_skill=false вместо skill используется transcript (после фильтрации).
     */
    public function testStepHistorySummarizationUsesTranscriptWhenSkillDisabled(): void
    {
        $config = [
            'orchestrator' => [
                'step_history_summarize' => [
                    'enabled' => true,
                    'use_skill' => false,
                    'mode' => 'append_summary',
                    'min_transcript_chars' => 0,
                    'filter' => [
                        'tool_messages' => true,
                        'history_tools' => true,
                        'min_message_chars' => 0,
                        'dedup_consecutive' => false,
                    ],
                ],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config.jsonc',
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]), 'config.jsonc');
        $configApp = ConfigurationApp::getInstance();
        $configApp->setSessionKey('20250101-120000-1-0');

        $agent = $configApp->getAgent('default');
        $this->assertNotNull($agent);

        $init = $configApp->getTodoList('init');
        $step = $configApp->getTodoList('step');
        $finish = $configApp->getTodoList('finish');
        $this->assertNotNull($init);
        $this->assertNotNull($step);
        $this->assertNotNull($finish);

        $init->setDefaultConfigurationAgent($agent);
        $step->setDefaultConfigurationAgent($agent);
        $finish->setDefaultConfigurationAgent($agent);

        OrchestratorSpyProvider::setCompleteOnStepCall(2);

        $orchestrator = new TestableTodoListOrchestrator($configApp);
        $result = $orchestrator->run($init, $step, $finish, 5, false, 0);
        $this->assertTrue($result->isSuccess());

        $history = $agent->getChatHistory();
        $messages = method_exists($history, 'getFullMessages') ? $history->getFullMessages() : $history->getMessages();
        $contents = array_map(static fn ($m) => (string) ($m->getContent() ?? ''), $messages);

        // В режиме append_summary сервис добавляет по одному transcript-summary на каждую итерацию step.
        $summaryTranscripts = array_values(array_filter($contents, static function (string $c): bool {
            return str_contains($c, '[') && str_contains($c, 'STEP');
        }));
        $this->assertCount(2, $summaryTranscripts);

        // Внутри каждого transcript step-дельта должна быть дедуплицирована.
        $this->assertSame(1, substr_count($summaryTranscripts[0], 'STEP'));
        $this->assertSame(1, substr_count($summaryTranscripts[1], 'STEP'));
    }

    /**
     * Проверка нормализации completed на широком наборе входных значений.
     */
    #[DataProvider('provideCompletedNormalizationCases')]
    public function testNormalizeCompleted(mixed $raw, ?int $expected): void
    {
        $orchestrator = new TestableTodoListOrchestrator($this->configApp);
        $this->assertSame($expected, $orchestrator->normalizeProxy($raw));
    }

    /**
     * Набор данных >=10, включая граничные и невалидные значения.
     *
     * @return array<string,array{0:mixed,1:?int}>
     */
    /**
     * Проверка {@see TodoListOrchestrator} / resume: индекс старта и фильтрация чекпоинта по имени списка и сессии.
     */
    #[DataProvider('provideResolveStartFromTodoIndexCases')]
    public function testResolveStartFromTodoIndexForTodoList(
        string $caseComment,
        ?RunStateDto $checkpoint,
        string $todoListKey,
        int $expectedIndex
    ): void {
        RunStateCheckpointHelper::delete($this->configApp->getSessionKey(), RunStateDto::DEF_AGENT_NAME);
        if ($checkpoint !== null) {
            RunStateCheckpointHelper::write($checkpoint);
        }

        $map = [
            'init' => $this->init,
            'step' => $this->step,
            'finish' => $this->finish,
        ];
        $this->assertArrayHasKey($todoListKey, $map);

        $orchestrator = new TestableTodoListOrchestrator($this->configApp);
        $actual = $orchestrator->resolveStartFromTodoIndexProxy($map[$todoListKey]);
        $this->assertSame($expectedIndex, $actual, $caseComment);
    }

    /**
     * Данные для {@see testResolveStartFromTodoIndexForTodoList}: граничные индексы, несовпадение имён/сессии, finished.
     *
     * @return iterable<string, array{0:string,1:?RunStateDto,2:string,3:int}>
     */
    public static function provideResolveStartFromTodoIndexCases(): iterable
    {
        $session = '20250101-120000-1-0';

        $baseStep = static function () use ($session): RunStateDto {
            return (new RunStateDto())
                ->setSessionKey($session)
                ->setAgentName(RunStateDto::DEF_AGENT_NAME)
                ->setRunId('run-test')
                ->setStartedAt('2025-01-01T00:00:00+00:00')
                ->setTodolistName('step')
                ->setLastCompletedTodoIndex(-1)
                ->setHistoryMessageCount(0)
                ->setGotoRequestedTodoIndex(null)
                ->setGotoTransitionsCount(0)
                ->setFinished(false);
        };

        // Нет файла чекпоинта — всегда 0.
        yield 'no_checkpoint_file' => [
            'без чекпоинта стартуем с пункта 0',
            null,
            'step',
            0,
        ];

        // Чекпоинт с finished=true не используется для продолжения.
        yield 'checkpoint_finished_ignored' => [
            'finished=true — не resume',
            $baseStep()->setFinished(true),
            'step',
            0,
        ];

        // Имя списка в DTO не совпадает с исполняемым списком.
        yield 'todolist_name_mismatch_init_vs_step_checkpoint' => [
            'в файле step, запрашиваем init — индекс 0',
            $baseStep(),
            'init',
            0,
        ];

        // Несовпадение session_key в чекпоинте с текущей сессией приложения.
        yield 'session_key_mismatch' => [
            'другой session_key — не resume',
            $baseStep()->setSessionKey('other-session-key-xxx'),
            'step',
            0,
        ];

        // last_completed = -1 → следующий пункт 0.
        yield 'resume_after_minus_one' => [
            'после -1 следующий индекс 0',
            $baseStep()->setLastCompletedTodoIndex(-1),
            'step',
            0,
        ];

        // last_completed = 0 → продолжить с 1.
        yield 'resume_after_zero' => [
            'после пункта 0 стартуем с 1',
            $baseStep()->setLastCompletedTodoIndex(0),
            'step',
            1,
        ];

        // Высокий индекс последнего завершённого пункта.
        yield 'resume_after_large_index' => [
            'после пункта 11 стартуем с 12',
            $baseStep()->setLastCompletedTodoIndex(11),
            'step',
            12,
        ];

        // Поля goto в чекпоинте не влияют на вычисление стартового индекса (обрабатываются внутри TodoList).
        yield 'goto_fields_do_not_change_start_index' => [
            'goto в dto не меняют startFromTodoIndex',
            $baseStep()->setLastCompletedTodoIndex(2)->setGotoRequestedTodoIndex(0),
            'step',
            3,
        ];

        // history_message_count = null: индекс всё равно вычисляется (как в TodolistCommand).
        yield 'resume_without_history_message_count' => [
            'без history_message_count индекс last+1 сохраняется',
            $baseStep()->setHistoryMessageCount(null)->setLastCompletedTodoIndex(4),
            'step',
            5,
        ];

        // Чекпоинт для finish и тот же список finish.
        yield 'finish_list_match' => [
            'resume для списка finish',
            $baseStep()->setTodolistName('finish')->setLastCompletedTodoIndex(0),
            'finish',
            1,
        ];

        // Отрицательный last_completed ниже -1: max(0, n+1) даёт 0.
        yield 'last_completed_strongly_negative_clamped' => [
            'сильно отрицательный last_completed даёт старт 0',
            $baseStep()->setLastCompletedTodoIndex(-10),
            'step',
            0,
        ];

        // Чекпоинт step при запросе finish — имя не совпадает.
        yield 'step_checkpoint_when_running_finish' => [
            'чекпоинт step не применяется к списку finish',
            $baseStep()->setLastCompletedTodoIndex(1),
            'finish',
            0,
        ];
    }

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
