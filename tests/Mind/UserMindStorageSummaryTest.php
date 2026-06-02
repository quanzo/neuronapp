<?php

declare(strict_types=1);

namespace Tests\Mind;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\mind\dto\MindSessionMetaDto;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\UserMindStorage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты суммаризации в {@see UserMindStorage}.
 */
final class UserMindStorageSummaryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_umind_sum_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.mind', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        file_put_contents($this->tmpDir . '/config.jsonc', "{\"mind\":{\"collect\":true}}\n");
        ConfigurationApp::init($dp, 'config.jsonc', 503);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);
        $this->removeTree($this->tmpDir);
    }

    /**
     * Служебный sessionKey не суммаризируется.
     */
    public function testRefreshSessionSummaryReturnsFalseForSummarySessionKey(): void
    {
        $mainKey = '20260602-main-summary-key';
        $summaryKey = MindSummarySessionKeyHelper::forMainSession($mainKey);
        $mind = $this->createMindWithMeta($summaryKey, 2, '');

        $app = ConfigurationApp::getInstance();
        $this->assertFalse($mind->refreshSessionSummary($app, $summaryKey));
        $this->assertSame('', $mind->getSessionsIndex()->get($summaryKey)?->getSummary());
    }

    /**
     * Несуществующая сессия — false без вызова LLM.
     */
    public function testRefreshSessionSummaryReturnsFalseForUnknownSession(): void
    {
        $mind = new UserMindStorage(new MindPaths(ConfigurationApp::getInstance()->getMindDir(), 503));
        $this->assertFalse($mind->refreshSessionSummary(ConfigurationApp::getInstance(), 'no-such-session'));
    }

    /**
     * messageCount=0 — false.
     */
    public function testRefreshSessionSummaryReturnsFalseWhenMessageCountZero(): void
    {
        $sessionKey = '20260602-empty-count';
        $mind = $this->createMindWithMeta($sessionKey, 0, '');
        $this->assertFalse($mind->refreshSessionSummary(ConfigurationApp::getInstance(), $sessionKey));
    }

    /**
     * Без агента в конфиге summary не меняется — false.
     */
    public function testRefreshSessionSummaryReturnsFalseWithoutConfiguredAgent(): void
    {
        $sessionKey = '20260602-no-agent';
        $mind = $this->createMindWithMessages($sessionKey);
        $this->assertFalse($mind->refreshSessionSummary(ConfigurationApp::getInstance(), $sessionKey));
        $this->assertSame('', $mind->getSessionsIndex()->get($sessionKey)?->getSummary());
    }

    /**
     * Делегирование в сервис: stub обновляет summary и получает тот же storage.
     */
    public function testRefreshSessionSummaryDelegatesToInjectedService(): void
    {
        $sessionKey = '20260602-stub-service';
        $mind = $this->createMindWithMessages($sessionKey);
        $stub = new RecordingMindSessionSummaryRefresher();

        $app = ConfigurationApp::getInstance();
        $this->assertTrue($mind->refreshSessionSummary($app, $sessionKey, $stub));

        $calls = $stub->getCalls();
        $this->assertCount(1, $calls);
        $this->assertSame($mind, $calls[0]['mind']);
        $this->assertSame($sessionKey, $calls[0]['sessionKey']);
        $this->assertStringContainsString($sessionKey, $mind->getSessionsIndex()->get($sessionKey)?->getSummary() ?? '');
    }

    /**
     * refreshAll: две основные сессии и одна служебная — attempted=2, skipped>=1.
     */
    public function testRefreshAllSessionSummariesSkipsSummarySessions(): void
    {
        $mainA = '20260602-all-a';
        $mainB = '20260602-all-b';
        $summaryKey = MindSummarySessionKeyHelper::forMainSession($mainA);

        $paths = new MindPaths(ConfigurationApp::getInstance()->getMindDir(), 503);
        $mind = new UserMindStorage($paths);
        $mind->appendMessage($mainA, 'user', 'A1');
        $mind->appendMessage($mainB, 'user', 'B1');

        $index = $mind->getSessionsIndex();
        $index->upsert(
            (new MindSessionMetaDto())
                ->setSessionKey($summaryKey)
                ->setStorageKey($paths->getStorageKey($summaryKey))
                ->setFirstCapturedAt('2026-06-02T10:00:00+00:00')
                ->setLastCapturedAt('2026-06-02T10:01:00+00:00')
                ->setMessageCount(1)
                ->setSummary('')
        );

        $stub = new RecordingMindSessionSummaryRefresher();
        $result = $mind->refreshAllSessionSummaries(ConfigurationApp::getInstance(), $stub);

        $this->assertSame(2, $result->getAttempted());
        $this->assertSame(2, $result->getUpdated());
        $this->assertGreaterThanOrEqual(1, $result->getSkipped());
        $this->assertCount(2, $stub->getCalls());
    }

    /**
     * Повторный refresh с тем же stub-summary — false (summary не изменился).
     */
    public function testRefreshSessionSummaryReturnsFalseWhenSummaryUnchanged(): void
    {
        $sessionKey = '20260602-unchanged';
        $mind = $this->createMindWithMessages($sessionKey);
        $stub = new RecordingMindSessionSummaryRefresher();
        $app = ConfigurationApp::getInstance();

        $this->assertTrue($mind->refreshSessionSummary($app, $sessionKey, $stub));
        $this->assertFalse($mind->refreshSessionSummary($app, $sessionKey, $stub));
    }

    private function createMindWithMessages(string $sessionKey): UserMindStorage
    {
        $paths = new MindPaths(ConfigurationApp::getInstance()->getMindDir(), 503);
        $mind = new UserMindStorage($paths);
        $mind->appendMessage($sessionKey, 'user', 'Текст');
        $mind->appendMessage($sessionKey, 'assistant', 'Ответ');

        return $mind;
    }

    private function createMindWithMeta(string $sessionKey, int $messageCount, string $summary): UserMindStorage
    {
        $paths = new MindPaths(ConfigurationApp::getInstance()->getMindDir(), 503);
        $mind = new UserMindStorage($paths);
        $mind->getSessionsIndex()->upsert(
            (new MindSessionMetaDto())
                ->setSessionKey($sessionKey)
                ->setStorageKey($paths->getStorageKey($sessionKey))
                ->setFirstCapturedAt('2026-06-02T10:00:00+00:00')
                ->setLastCapturedAt('2026-06-02T10:01:00+00:00')
                ->setMessageCount($messageCount)
                ->setSummary($summary)
        );

        return $mind;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
