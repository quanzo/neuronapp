<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\storage\IntermediateStorage;
use app\modules\neuron\tools\IntermediateListTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see IntermediateListTool}.
 *
 * Проверяют:
 * - возврат правильного количества элементов и списка labels.
 */
final class IntermediateListToolTest extends TestCase
{
    private string $tmpDir;
    private IntermediateListTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_intermediate_list_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->tool = new IntermediateListTool();
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * list должен возвращать корректный список и count.
     */
    public function testListReturnsItems(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $storage = new IntermediateStorage($this->tmpDir . '/.store');
        $storage->save($sessionKey, 'a', '1', 'one');
        $storage->save($sessionKey, 'b', '2', 'two');

        $json = ($this->tool)();
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['count']);
        $this->assertCount(2, $data['items']);
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
