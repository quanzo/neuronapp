<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\storage\VarStorage;
use app\modules\neuron\tools\VarGetTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see VarGetTool}.
 */
final class VarGetToolTest extends TestCase
{
    private string $tmpDir;
    private VarGetTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_var_get_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->tool = new VarGetTool();
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testGetExisting(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $storage = new VarStorage($this->tmpDir . '/.store');
        $storage->save($sessionKey, 'parsed', ['x' => 1], 'Короткое описание');

        $json = ($this->tool)('parsed');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame('get', $data['action']);
        $this->assertSame(['x' => 1], $data['data']);
    }

    public function testGetStringRange(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $storage = new VarStorage($this->tmpDir . '/.store');
        $storage->save($sessionKey, 'text', "l1\nl2\nl3\nl4", 'Text');

        $json = ($this->tool)('text', 2, 3);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame("l2\nl3", $data['data']);
        $this->assertSame(2, $data['startLine']);
        $this->assertSame(3, $data['endLine']);
        $this->assertSame(4, $data['totalLines']);
    }

    public function testGetMissing(): void
    {
        $json = ($this->tool)('missing');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertFalse($data['exists']);
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
