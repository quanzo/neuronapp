<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\tools\IntermediatePadTool;
use app\modules\neuron\tools\IntermediateLoadTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see IntermediatePadTool}.
 */
final class IntermediatePadToolTest extends TestCase
{
    private string $tmpDir;
    private IntermediatePadTool $padTool;
    private IntermediateLoadTool $loadTool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_intermediate_pad_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->padTool = new IntermediatePadTool();
        $this->loadTool = new IntermediateLoadTool();
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * Pad создаёт запись, если её нет, и дополняет с переводом строк.
     */
    public function testPadCreatesAndAppendsWithNewline(): void
    {
        $json1 = ($this->padTool)('log', 'Append log', 'first');
        $d1 = json_decode($json1, true);
        $this->assertTrue($d1['success']);

        $json2 = ($this->padTool)('log', 'Append log', 'second');
        $d2 = json_decode($json2, true);
        $this->assertTrue($d2['success']);

        $loadedJson = ($this->loadTool)('log');
        $loaded = json_decode($loadedJson, true);
        $this->assertSame("first\nsecond", $loaded['data']);
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

