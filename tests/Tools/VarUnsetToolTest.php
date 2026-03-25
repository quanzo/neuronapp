<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\storage\VarStorage;
use app\modules\neuron\tools\VarUnsetTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see VarUnsetTool}.
 */
final class VarUnsetToolTest extends TestCase
{
    private string $tmpDir;
    private VarUnsetTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_var_unset_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->tool = new VarUnsetTool();
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testUnsetExisting(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $storage = new VarStorage($this->tmpDir . '/.store');
        $storage->save($sessionKey, 'tmp', '1', 'tmp value');
        $this->assertTrue($storage->exists($sessionKey, 'tmp'));

        $json = ($this->tool)('tmp');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame('unset', $data['action']);
        $this->assertFalse($storage->exists($sessionKey, 'tmp'));
    }

    public function testUnsetMissing(): void
    {
        $json = ($this->tool)('nope');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame('unset', $data['action']);
    }

    public function testEmptyLabelReturnsError(): void
    {
        $json = ($this->tool)('   ');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
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
