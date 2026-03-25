<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\storage\StoreStorage;
use app\modules\neuron\tools\StoreExistTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see StoreExistTool}.
 *
 * Проверяют:
 * - exist=true для существующей метки
 * - exist=false для отсутствующей метки
 * - ошибку при пустой метке
 */
final class StoreExistToolTest extends TestCase
{
    private string $tmpDir;
    private StoreExistTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_store_exist_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->tool = new StoreExistTool();
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testExistTrueAndFalse(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $storage = new StoreStorage($this->tmpDir . '/.store');
        $storage->save($sessionKey, 'x', '1', 'desc');

        $json1 = ($this->tool)('x');
        $d1 = json_decode($json1, true);
        $this->assertTrue($d1['success']);
        $this->assertTrue($d1['exists']);

        $json2 = ($this->tool)('missing');
        $d2 = json_decode($json2, true);
        $this->assertTrue($d2['success']);
        $this->assertFalse($d2['exists']);
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
