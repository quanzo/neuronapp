<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\command\MindSessionSummaryCommand;
use app\modules\neuron\interfaces\MindSessionSummaryRefresherInterface;
use app\modules\neuron\mind\dto\MindSessionMetaDto;
use app\modules\neuron\mind\dto\config\MindConfigDto;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\services\MindSessionSummaryService;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\UserMindStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Mind\RecordingMindSessionSummaryRefresher;

/**
 * Тесты {@see MindSessionSummaryCommand}: валидация CLI и вызов refresh summary без блока mind в app config.
 */
final class MindSessionSummaryCommandTest extends TestCase
{
    private const int TEST_USER_ID = 601;

    private const string STUB_AGENT = 'stub_summarizer';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->resetConfigurationApp();
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_mind_cmd_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.mind', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationApp();
        $this->removeTree($this->tmpDir);
    }

    /**
     * Опции session_id и agent объявлены как обязательные.
     */
    public function testConfigureRequiresSessionIdAndAgentOptions(): void
    {
        $command = new MindSessionSummaryCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('session_id'));
        $this->assertTrue($definition->getOption('session_id')->isValueRequired());
        $this->assertTrue($definition->hasOption('agent'));
        $this->assertTrue($definition->getOption('agent')->isValueRequired());
        $this->assertTrue($definition->hasOption('max-summary-chars'));
        $this->assertTrue($definition->hasOption('transcript-ratio'));
    }

    /**
     * Без --session_id команда завершается с ошибкой.
     */
    public function testExecuteFailsWhenSessionIdMissing(): void
    {
        $this->initAppWithMindConfig('{}');
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher(), false);

        $exitCode = $tester->execute(['--agent' => self::STUB_AGENT]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('session_id', $tester->getDisplay());
    }

    /**
     * Без --agent команда завершается с ошибкой.
     */
    public function testExecuteFailsWhenAgentMissing(): void
    {
        $this->initAppWithMindConfig('{}');
        $sessionKey = $this->validSessionKey('no-agent');
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher(), false);

        $exitCode = $tester->execute(['--session_id' => $sessionKey]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('--agent', $tester->getDisplay());
    }

    /**
     * Невалидный формат session_id — FAILURE.
     */
    public function testExecuteFailsWhenSessionIdFormatInvalid(): void
    {
        $this->initAppWithMindConfig('{}');
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher(), false);

        $exitCode = $tester->execute([
            '--session_id' => 'not-a-valid-key',
            '--agent'      => self::STUB_AGENT,
        ]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('формат', $tester->getDisplay());
    }

    /**
     * Служебный ключ :__mind_summary__ отклоняется.
     */
    public function testExecuteFailsForSummaryServiceSessionKey(): void
    {
        $this->initAppWithMindConfig('{}');
        $mainKey = $this->validSessionKey('main-svc');
        $summaryKey = MindSummarySessionKeyHelper::forMainSession($mainKey);
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher(), false);

        $exitCode = $tester->execute([
            '--session_id' => $summaryKey,
            '--agent'      => self::STUB_AGENT,
        ]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('служебную', $tester->getDisplay());
    }

    /**
     * Сессия отсутствует в индексе .mind — FAILURE.
     */
    public function testExecuteFailsWhenSessionNotInMindIndex(): void
    {
        $this->initAppWithMindConfig('{}');
        $sessionKey = $this->validSessionKey('missing-index');
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());

        $exitCode = $tester->execute([
            '--session_id' => $sessionKey,
            '--agent'      => self::STUB_AGENT,
        ]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('отсутствует', $tester->getDisplay());
    }

    /**
     * messageCount=0 в meta — FAILURE.
     */
    public function testExecuteFailsWhenMessageCountZero(): void
    {
        $this->initAppWithMindConfig('{}');
        $sessionKey = $this->validSessionKey('zero-msgs');
        $this->upsertMeta($sessionKey, 0, '');

        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());
        $exitCode = $tester->execute([
            '--session_id' => $sessionKey,
            '--agent'      => self::STUB_AGENT,
        ]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('messageCount=0', $tester->getDisplay());
    }

    /**
     * --agent указывает на несуществующего агента — FAILURE.
     */
    public function testExecuteFailsWhenAgentOptionNotFound(): void
    {
        $this->initAppWithMindConfig('{}');
        $sessionKey = $this->validSessionKey('bad-agent-opt');
        $this->upsertMeta($sessionKey, 1, '');

        $tester = new CommandTester(
            new TestableMindSessionSummaryCommand(new RecordingMindSessionSummaryRefresher(), null),
        );
        $exitCode = $tester->execute([
            '--session_id' => $sessionKey,
            '--agent'      => 'no_such_agent_xyz',
        ]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('не найден', $tester->getDisplay());
    }

    /**
     * Невалидный --max-summary-chars — FAILURE.
     */
    public function testExecuteFailsWhenMaxSummaryCharsInvalid(): void
    {
        $this->initAppWithMindConfig('{}');
        $sessionKey = $this->validSessionKey('bad-chars');
        $this->upsertMeta($sessionKey, 1, '');

        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());
        $exitCode = $tester->execute([
            '--session_id'         => $sessionKey,
            '--agent'              => self::STUB_AGENT,
            '--max-summary-chars'  => '10',
        ]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('max-summary-chars', $tester->getDisplay());
    }

    /**
     * Успешный refresh через stub без блока mind в app config.
     */
    public function testExecuteSuccessWithoutMindBlockInAppConfig(): void
    {
        $this->initAppWithMindConfig('{}');
        $sessionKey = $this->validSessionKey('stub-ok');
        $this->upsertMeta($sessionKey, 3, '');

        $stub = new RecordingMindSessionSummaryRefresher();
        $tester = $this->createCommandTester($stub);
        $exitCode = $tester->execute([
            '--session_id' => $sessionKey,
            '--agent'      => self::STUB_AGENT,
        ]);

        $this->assertSame(MindSessionSummaryCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('stub summary for ' . $sessionKey, $tester->getDisplay());
        $this->assertCount(1, $stub->getCalls());
    }

    /**
     * Повторный вызов при неизменившемся summary — SUCCESS с предупреждением.
     */
    public function testExecuteSuccessWhenSummaryUnchanged(): void
    {
        $this->initAppWithMindConfig('{}');
        $sessionKey = $this->validSessionKey('unchanged');
        $existing = 'stub summary for ' . $sessionKey;
        $this->upsertMeta($sessionKey, 2, $existing);

        $stub = new RecordingMindSessionSummaryRefresher();
        $tester = $this->createCommandTester($stub);
        $exitCode = $tester->execute([
            '--session_id' => $sessionKey,
            '--agent'      => self::STUB_AGENT,
        ]);

        $this->assertSame(MindSessionSummaryCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('не изменился', $tester->getDisplay());
    }

    /**
     * createSummaryRefresher по умолчанию возвращает MindSessionSummaryService.
     */
    public function testDefaultSummaryRefresherUsesFromMindConfig(): void
    {
        $this->initAppWithMindConfig('{}');
        $app = ConfigurationApp::getInstance();
        $effective = MindConfigDto::fromConfigArray([
            'session_summary' => [
                'agent' => 'test_agent',
                'max_summary_chars' => 200,
            ],
        ]);

        $command = new MindSessionSummaryCommand();
        $ref = new \ReflectionMethod(MindSessionSummaryCommand::class, 'createSummaryRefresher');
        $service = $ref->invoke($command, $app, $effective);

        $this->assertInstanceOf(MindSessionSummaryService::class, $service);
    }

    /**
     * Пустой session_id — FAILURE.
     */
    public function testExecuteFailsWhenSessionIdIsWhitespaceOnly(): void
    {
        $this->initAppWithMindConfig('{}');
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());

        $exitCode = $tester->execute([
            '--session_id' => '   ',
            '--agent'      => self::STUB_AGENT,
        ]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
    }

    /**
     * @param bool $withSummarizerTemplate false, если execute завершится до загрузки агента.
     */
    private function createCommandTester(
        MindSessionSummaryRefresherInterface $refresher,
        bool $withSummarizerTemplate = true,
    ): CommandTester {
        $summarizer = null;
        if ($withSummarizerTemplate) {
            $app = ConfigurationApp::getInstance();
            $summarizer = ConfigurationAgent::makeFromArray(['contextWindow' => 8000], $app);
            $this->assertNotNull($summarizer);
        }

        return new CommandTester(new TestableMindSessionSummaryCommand($refresher, $summarizer));
    }

    private function initAppWithMindConfig(string $configJson): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        file_put_contents($this->tmpDir . '/config.jsonc', $configJson . "\n");
        ConfigurationApp::init($dp, 'config.jsonc', self::TEST_USER_ID);
    }

    private function validSessionKey(string $suffix): string
    {
        return '20250301-143022-123456-' . self::TEST_USER_ID;
    }

    private function upsertMeta(string $sessionKey, int $messageCount, string $summary): void
    {
        $app = ConfigurationApp::getInstance();
        $paths = new MindPaths($app->getMindDir(), self::TEST_USER_ID);
        $mind = new UserMindStorage($paths);
        $mind->getSessionsIndex()->upsert(
            (new MindSessionMetaDto())
                ->setSessionKey($sessionKey)
                ->setStorageKey($paths->getStorageKey($sessionKey))
                ->setFirstCapturedAt('2026-06-02T10:00:00+00:00')
                ->setLastCapturedAt('2026-06-02T10:01:00+00:00')
                ->setMessageCount($messageCount)
                ->setSummary($summary),
        );
    }

    private function resetConfigurationApp(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
