<?php

declare(strict_types=1);

namespace Tests\Mind;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\mind\dto\config\MindConfigDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see MindConfigDto::resolveEffective()}: explicit, agent merge, app-only.
 */
final class MindConfigDtoResolveEffectiveTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_resolve_eff_' . uniqid();
        mkdir($this->tmpDir, 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        file_put_contents(
            $this->tmpDir . '/config.jsonc',
            "{\"mind\":{\"collect\":false,\"session_summary\":{\"agent\":\"app_agent\",\"max_summary_chars\":300}}}\n"
        );
        ConfigurationApp::init($dp, 'config.jsonc', 505);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);
        $this->removeTree($this->tmpDir);
    }

    /**
     * explicit перекрывает merge app + agent.
     */
    public function testExplicitOverridesAgentMerge(): void
    {
        $app = ConfigurationApp::getInstance();
        $agent = $this->agentWithMind(['collect' => true]);
        $explicit = MindConfigDto::fromConfigArray(['collect' => false]);

        $effective = MindConfigDto::resolveEffective($app, $agent, $explicit);

        $this->assertFalse($effective->resolveCollect(true));
    }

    /**
     * explicit без agent — возвращается как есть.
     */
    public function testExplicitWithoutAgent(): void
    {
        $app = ConfigurationApp::getInstance();
        $explicit = MindConfigDto::fromConfigArray(['collect' => true]);

        $effective = MindConfigDto::resolveEffective($app, null, $explicit);

        $this->assertTrue($effective->resolveCollect(false));
    }

    /**
     * agent=null — только app.
     */
    public function testAppOnlyWhenAgentNull(): void
    {
        $app = ConfigurationApp::getInstance();
        $effective = MindConfigDto::resolveEffective($app);

        $this->assertSame($app->getMindConfig()->resolveCollect(false), $effective->resolveCollect(false));
        $this->assertSame('app_agent', $effective->resolveSessionSummary()->resolveAgent());
    }

    /**
     * agent без блока mind — effective = app.
     */
    public function testAgentWithoutMindBlockUsesApp(): void
    {
        $app = ConfigurationApp::getInstance();
        $agent = ConfigurationAgent::makeFromArray(['contextWindow' => 8000], $app);
        $this->assertNotNull($agent);

        $effective = MindConfigDto::resolveEffective($app, $agent);

        $this->assertFalse($effective->resolveCollect(true));
        $this->assertSame('app_agent', $effective->resolveSessionSummary()->resolveAgent());
    }

    /**
     * agent collect=true перекрывает app collect=false.
     */
    public function testAgentCollectOverridesApp(): void
    {
        $app = ConfigurationApp::getInstance();
        $agent = $this->agentWithMind(['collect' => true]);

        $effective = MindConfigDto::resolveEffective($app, $agent);

        $this->assertTrue($effective->resolveCollect(false));
    }

    /**
     * agent session_summary.agent перекрывает app.
     */
    public function testAgentSummaryAgentOverridesApp(): void
    {
        $app = ConfigurationApp::getInstance();
        $agent = $this->agentWithMind([
            'session_summary' => ['agent' => 'overlay_agent', 'max_summary_chars' => 120],
        ]);

        $effective = MindConfigDto::resolveEffective($app, $agent);
        $summary = $effective->resolveSessionSummary();

        $this->assertSame('overlay_agent', $summary->resolveAgent());
        $this->assertSame(120, $summary->resolveMaxSummaryChars());
    }

    /**
     * agent collect=null сохраняет app collect=false.
     */
    public function testAgentCollectNullPreservesAppCollect(): void
    {
        $app = ConfigurationApp::getInstance();
        $agent = $this->agentWithMind(['session_summary' => ['agent' => 'only_summary']]);

        $effective = MindConfigDto::resolveEffective($app, $agent);

        $this->assertFalse($effective->resolveCollect(true));
        $this->assertSame('only_summary', $effective->resolveSessionSummary()->resolveAgent());
    }

    /**
     * resolveEffective эквивалентен ConfigurationAgent::resolveEffectiveMindConfig.
     */
    public function testMatchesConfigurationAgentWrapper(): void
    {
        $app = ConfigurationApp::getInstance();
        $agent = $this->agentWithMind([
            'collect' => true,
            'session_summary' => ['agent' => 'wrapper_agent'],
        ]);

        $fromDto = MindConfigDto::resolveEffective($app, $agent);
        $fromAgent = $agent->resolveEffectiveMindConfig($app);

        $this->assertSame($fromDto->resolveCollect(false), $fromAgent->resolveCollect(false));
        $this->assertSame(
            $fromDto->resolveSessionSummary()->resolveAgent(),
            $fromAgent->resolveSessionSummary()->resolveAgent()
        );
    }

    /**
     * explicit с пустым DTO возвращается как есть (collect null, summary null).
     */
    public function testExplicitEmptyStillWinsOverApp(): void
    {
        $app = ConfigurationApp::getInstance();
        $explicit = MindConfigDto::empty();

        $effective = MindConfigDto::resolveEffective($app, null, $explicit);

        $this->assertNull($effective->getCollect());
        $this->assertFalse($effective->resolveCollect(false));
        $this->assertNull($effective->getSessionSummary());
    }

    /**
     * agent collect=false перекрывает app при гипотетическом app collect=true.
     */
    public function testAgentCollectFalseOverridesAppTrue(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);

        $dp = new DirPriority([$this->tmpDir]);
        file_put_contents($this->tmpDir . '/config.jsonc', "{\"mind\":{\"collect\":true}}\n");
        ConfigurationApp::init($dp, 'config.jsonc', 506);

        $app = ConfigurationApp::getInstance();
        $agent = $this->agentWithMind(['collect' => false]);

        $effective = MindConfigDto::resolveEffective($app, $agent);

        $this->assertFalse($effective->resolveCollect(true));
    }

    /**
     * transcript_ratio из agent merge с app.
     */
    public function testAgentTranscriptRatioOverlay(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);

        $dp = new DirPriority([$this->tmpDir]);
        file_put_contents(
            $this->tmpDir . '/config.jsonc',
            "{\"mind\":{\"session_summary\":{\"transcript_ratio\":0.5}}}\n"
        );
        ConfigurationApp::init($dp, 'config.jsonc', 507);

        $app = ConfigurationApp::getInstance();
        $agent = $this->agentWithMind([
            'session_summary' => ['transcript_ratio' => 0.2],
        ]);

        $effective = MindConfigDto::resolveEffective($app, $agent);

        $this->assertSame(0.2, $effective->resolveSessionSummary()->resolveTranscriptRatio());
    }

    /**
     * agent=null и explicit=null — тот же результат, что getMindConfig app.
     */
    public function testDefaultArgsSameAsAppGetMindConfig(): void
    {
        $app = ConfigurationApp::getInstance();
        $effective = MindConfigDto::resolveEffective($app, null, null);

        $this->assertSame(
            $app->getMindConfig()->resolveSessionSummary()->resolveMaxSummaryChars(),
            $effective->resolveSessionSummary()->resolveMaxSummaryChars()
        );
    }

    /**
     * @param array<string, mixed> $mindBlock
     */
    private function agentWithMind(array $mindBlock): ConfigurationAgent
    {
        $app = ConfigurationApp::getInstance();
        $agent = ConfigurationAgent::makeFromArray([
            'contextWindow' => 8000,
            'mind' => $mindBlock,
        ], $app);
        $this->assertNotNull($agent);

        return $agent;
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
