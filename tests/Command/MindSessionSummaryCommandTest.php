<?php

declare(strict_types=1);

namespace Tests\Command;

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
 * Тесты {@see MindSessionSummaryCommand}: валидация session_id и вызов refresh summary.
 */
final class MindSessionSummaryCommandTest extends TestCase
{
    private const int TEST_USER_ID = 601;

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
     * Опция session_id объявлена как обязательная.
     */
    public function testConfigureRequiresSessionIdOption(): void
    {
        $command = new MindSessionSummaryCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('session_id'));
        $this->assertTrue($definition->getOption('session_id')->isValueRequired());
        $this->assertTrue($definition->hasOption('agent'));
    }

    /**
     * Без --session_id команда завершается с ошибкой.
     */
    public function testExecuteFailsWhenSessionIdMissing(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());

        $exitCode = $tester->execute([]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('session_id', $tester->getDisplay());
    }

    /**
     * Невалидный формат session_id — FAILURE.
     */
    public function testExecuteFailsWhenSessionIdFormatInvalid(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());

        $exitCode = $tester->execute(['--session_id' => 'not-a-valid-key']);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('формат', $tester->getDisplay());
    }

    /**
     * Служебный ключ :__mind_summary__ отклоняется.
     */
    public function testExecuteFailsForSummaryServiceSessionKey(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $mainKey = $this->validSessionKey('main-svc');
        $summaryKey = MindSummarySessionKeyHelper::forMainSession($mainKey);
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());

        $exitCode = $tester->execute(['--session_id' => $summaryKey]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('служебную', $tester->getDisplay());
    }

    /**
     * Сессия отсутствует в индексе .mind — FAILURE.
     */
    public function testExecuteFailsWhenSessionNotInMindIndex(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $sessionKey = $this->validSessionKey('missing-index');
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());

        $exitCode = $tester->execute(['--session_id' => $sessionKey]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('отсутствует', $tester->getDisplay());
    }

    /**
     * messageCount=0 в meta — FAILURE.
     */
    public function testExecuteFailsWhenMessageCountZero(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $sessionKey = $this->validSessionKey('zero-msgs');
        $this->upsertMeta($sessionKey, 0, '');

        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());
        $exitCode = $tester->execute(['--session_id' => $sessionKey]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('messageCount=0', $tester->getDisplay());
    }

    /**
     * Не задан session_summary.agent в effective config — FAILURE.
     */
    public function testExecuteFailsWhenSummarizerAgentNotConfigured(): void
    {
        $this->initAppWithMindConfig('{"mind":{"collect":true}}');
        $sessionKey = $this->validSessionKey('no-agent-cfg');
        $this->upsertMeta($sessionKey, 2, '');

        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());
        $exitCode = $tester->execute(['--session_id' => $sessionKey]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('session_summary.agent', $tester->getDisplay());
    }

    /**
     * --agent указывает на несуществующего агента — FAILURE.
     */
    public function testExecuteFailsWhenAgentOptionNotFound(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $sessionKey = $this->validSessionKey('bad-agent-opt');
        $this->upsertMeta($sessionKey, 1, '');

        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());
        $exitCode = $tester->execute([
            '--session_id' => $sessionKey,
            '--agent'      => 'no_such_agent_xyz',
        ]);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('не найден', $tester->getDisplay());
    }

    /**
     * Успешный refresh через stub: summary записан, код SUCCESS.
     */
    public function testExecuteSuccessWithStubRefresher(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $sessionKey = $this->validSessionKey('stub-ok');
        $this->upsertMeta($sessionKey, 3, '');

        $stub = new RecordingMindSessionSummaryRefresher();
        $tester = $this->createCommandTester($stub);
        $exitCode = $tester->execute(['--session_id' => $sessionKey]);

        $this->assertSame(MindSessionSummaryCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('stub summary for ' . $sessionKey, $tester->getDisplay());
        $this->assertCount(1, $stub->getCalls());
        $this->assertSame($sessionKey, $stub->getCalls()[0]['sessionKey']);
    }

    /**
     * Повторный вызов при неизменившемся summary — SUCCESS с предупреждением.
     */
    public function testExecuteSuccessWhenSummaryUnchanged(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $sessionKey = $this->validSessionKey('unchanged');
        $existing = 'stub summary for ' . $sessionKey;
        $this->upsertMeta($sessionKey, 2, $existing);

        $stub = new RecordingMindSessionSummaryRefresher();
        $tester = $this->createCommandTester($stub);
        $exitCode = $tester->execute(['--session_id' => $sessionKey]);

        $this->assertSame(MindSessionSummaryCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('не изменился', $tester->getDisplay());
        $this->assertStringContainsString($existing, $tester->getDisplay());
    }

    /**
     * createSummaryRefresher по умолчанию возвращает MindSessionSummaryService.
     */
    public function testDefaultSummaryRefresherIsMindSessionSummaryService(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $app = ConfigurationApp::getInstance();
        $command = new MindSessionSummaryCommand();
        $effective = MindConfigDto::resolveEffective($app);

        $ref = new \ReflectionMethod(MindSessionSummaryCommand::class, 'createSummaryRefresher');
        $service = $ref->invoke($command, $app, $effective);

        $this->assertInstanceOf(MindSessionSummaryService::class, $service);
    }

    /**
     * Пустой session_id как строка из опции — FAILURE.
     */
    public function testExecuteFailsWhenSessionIdIsWhitespaceOnly(): void
    {
        $this->initAppWithMindConfig('{"mind":{"session_summary":{"agent":"summarizer"}}}');
        $tester = $this->createCommandTester(new RecordingMindSessionSummaryRefresher());

        $exitCode = $tester->execute(['--session_id' => '   ']);

        $this->assertSame(MindSessionSummaryCommand::FAILURE, $exitCode);
    }

    private function createCommandTester(MindSessionSummaryRefresherInterface $refresher): CommandTester
    {
        return new CommandTester(new TestableMindSessionSummaryCommand($refresher));
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
