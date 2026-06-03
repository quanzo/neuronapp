<?php

declare(strict_types=1);

namespace Tests\Config;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use PHPUnit\Framework\TestCase;

/**
 * Тесты блока `mind` в {@see ConfigurationAgent} и merge с app.
 */
final class ConfigurationAgentMindConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_agent_mind_' . uniqid();
        mkdir($this->tmpDir, 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        file_put_contents(
            $this->tmpDir . '/config.jsonc',
            "{\"mind\":{\"collect\":false,\"session_summary\":{\"agent\":\"app_summarizer\",\"max_summary_chars\":300}}}\n"
        );
        ConfigurationApp::init($dp, 'config.jsonc', 504);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);
        $this->removeTree($this->tmpDir);
    }

    /**
     * makeFromArray загружает mind; getMindConfig возвращает DTO.
     */
    public function testMakeFromArrayLoadsMindConfig(): void
    {
        $app = ConfigurationApp::getInstance();
        $agent = ConfigurationAgent::makeFromArray([
            'contextWindow' => 8000,
            'mind' => [
                'collect' => true,
                'session_summary' => [
                    'agent' => 'agent_summarizer',
                ],
            ],
        ], $app);

        $this->assertNotNull($agent);
        $mind = $agent->getMindConfig();
        $this->assertNotNull($mind);
        $this->assertTrue($mind->getCollect());
        $this->assertSame('agent_summarizer', $mind->getSessionSummary()?->getAgent());
    }

    /**
     * resolveEffectiveMindConfig: agent перекрывает collect и agent name.
     */
    public function testResolveEffectiveMindConfigAgentPriority(): void
    {
        $app = ConfigurationApp::getInstance();
        $agent = ConfigurationAgent::makeFromArray([
            'contextWindow' => 8000,
            'mind' => [
                'collect' => true,
                'session_summary' => [
                    'agent' => 'agent_summarizer',
                    'max_summary_chars' => 150,
                ],
            ],
        ], $app);

        $this->assertNotNull($agent);
        $effective = $agent->resolveEffectiveMindConfig($app);

        $this->assertTrue($effective->resolveCollect(false));
        $summary = $effective->resolveSessionSummary();
        $this->assertSame('agent_summarizer', $summary->resolveAgent());
        $this->assertSame(150, $summary->resolveMaxSummaryChars());
    }

    /**
     * Без блока mind у агента — effective совпадает с app.
     */
    public function testResolveEffectiveWithoutAgentMindUsesApp(): void
    {
        $app = ConfigurationApp::getInstance();
        $agent = ConfigurationAgent::makeFromArray([
            'contextWindow' => 8000,
        ], $app);

        $this->assertNotNull($agent);
        $this->assertNull($agent->getMindConfig());
        $effective = $agent->resolveEffectiveMindConfig($app);
        $appMind = $app->getMindConfig();

        $this->assertFalse($effective->resolveCollect(true));
        $this->assertSame(
            $appMind->resolveSessionSummary()->resolveAgent(),
            $effective->resolveSessionSummary()->resolveAgent()
        );
    }

    /**
     * getMindConfig на app возвращает тот же смысл, что isLongTermMindCollectionEnabled.
     */
    public function testAppGetMindConfigMatchesCollectHelper(): void
    {
        $app = ConfigurationApp::getInstance();
        $this->assertSame(
            $app->isLongTermMindCollectionEnabled(),
            $app->getMindConfig()->resolveCollect(false)
        );
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
