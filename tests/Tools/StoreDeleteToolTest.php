<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\storage\StoreStorage;
use app\modules\neuron\tools\StoreDeleteTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see StoreDeleteTool}.
 *
 * Проверяют:
 * - успешное удаление существующей записи
 * - idempotent-поведение при отсутствии записи
 * - ошибку при пустой метке
 */
final class StoreDeleteToolTest extends TestCase
{
    private string $tmpDir;
    private StoreDeleteTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_store_delete_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->tool = new StoreDeleteTool();
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testDeleteExisting(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $storage = new StoreStorage($this->tmpDir . '/.store');
        $storage->save($sessionKey, 'tmp', '1', 'tmp value');
        $this->assertTrue($storage->exists($sessionKey, 'tmp'));

        $json = ($this->tool)('tmp');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertFalse($storage->exists($sessionKey, 'tmp'));
    }

    public function testDeleteMissing(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $storage = new StoreStorage($this->tmpDir . '/.store');
        $this->assertFalse($storage->exists($sessionKey, 'nope'));

        $json = ($this->tool)('nope');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
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
