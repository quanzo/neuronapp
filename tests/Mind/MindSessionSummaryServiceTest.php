<?php

declare(strict_types=1);

namespace Tests\Mind;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\mind\dto\MindSessionMetaDto;
use app\modules\neuron\mind\dto\config\MindConfigDto;
use app\modules\neuron\mind\services\MindSessionSummaryService;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\UserMindStorage;
use PHPUnit\Framework\TestCase;
use Tests\Mind\Support\CapturingHistoryHeadSummarizer;

/**
 * Тесты {@see MindSessionSummaryService}: передача previous summary в summarizer.
 */
final class MindSessionSummaryServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_mss_svc_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.mind', 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        file_put_contents($this->tmpDir . '/config.jsonc', "{\"mind\":{\"collect\":true}}\n");
        ConfigurationApp::init($dp, 'config.jsonc', 504);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);
        $this->removeTree($this->tmpDir);
    }

    /**
     * Первый refresh без summary в индексе — previousSummary null у summarizer.
     */
    public function testRefreshPassesNullPreviousSummaryWhenIndexEmpty(): void
    {
        $sessionKey = '20260602-svc-no-prev';
        $capturing = $this->runRefreshWithCapturing($sessionKey, '');

        $this->assertNull($capturing->getLastPreviousSummary());
    }

    /**
     * При непустом summary в индексе — то же значение уходит в summarize().
     */
    public function testRefreshPassesExistingSummaryAsPrevious(): void
    {
        $sessionKey = '20260602-svc-with-prev';
        $existing = 'Старое резюме: цель — рефакторинг mind.';
        $capturing = $this->runRefreshWithCapturing($sessionKey, $existing);

        $this->assertSame($existing, $capturing->getLastPreviousSummary());
    }

    private function runRefreshWithCapturing(string $sessionKey, string $existingSummary): CapturingHistoryHeadSummarizer
    {
        $paths = new MindPaths(ConfigurationApp::getInstance()->getMindDir(), 504);
        $mind = new UserMindStorage($paths);
        $mind->appendMessage($sessionKey, 'user', 'Новое сообщение в сессии');
        $mind->appendMessage($sessionKey, 'assistant', 'Ответ ассистента');

        $meta = (new MindSessionMetaDto())
            ->setSessionKey($sessionKey)
            ->setStorageKey($paths->getStorageKey($sessionKey))
            ->setFirstCapturedAt('2026-06-02T10:00:00+00:00')
            ->setLastCapturedAt('2026-06-02T10:05:00+00:00')
            ->setMessageCount(2)
            ->setSummary($existingSummary);
        $mind->getSessionsIndex()->upsert($meta);

        $template = new ConfigurationAgent();
        $template->setConfigurationApp(ConfigurationApp::getInstance());
        $template->setSessionKey('test-summarizer-template-session');
        $template->contextWindow = 8192;

        $mindConfig = MindConfigDto::fromConfigArray([
            'collect' => true,
            'session_summary' => [
                'agent' => 'test_summarizer',
                'max_summary_chars' => 300,
                'transcript_ratio' => 0.25,
            ],
        ]);

        $capturing = new CapturingHistoryHeadSummarizer();
        $service = MindSessionSummaryService::forTest($mindConfig, $template, $capturing);
        $service->refreshSessionSummary($mind, $sessionKey);

        $updated = $mind->getSessionsIndex()->get($sessionKey)?->getSummary() ?? '';
        $this->assertStringContainsString('stub-summary-from-capturing-summarizer', $updated);

        return $capturing;
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
