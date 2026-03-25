<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\tools\StorePadTool;
use app\modules\neuron\tools\StoreLoadTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see StorePadTool}.
 */
final class StorePadToolTest extends TestCase
{
    private string $tmpDir;
    private StorePadTool $padTool;
    private StoreLoadTool $loadTool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_store_pad_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->padTool = new StorePadTool();
        $this->loadTool = new StoreLoadTool();
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
