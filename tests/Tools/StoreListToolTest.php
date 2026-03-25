<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\storage\StoreStorage;
use app\modules\neuron\tools\StoreListTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see StoreListTool}.
 *
 * Проверяют:
 * - возврат правильного количества элементов и списка items.
 */
final class StoreListToolTest extends TestCase
{
    private string $tmpDir;
    private StoreListTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_store_list_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->tool = new StoreListTool();
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
        $storage = new StoreStorage($this->tmpDir . '/.store');
        $storage->save($sessionKey, 'a', '1', 'one');
        $storage->save($sessionKey, 'b', '2', 'two');

        $json = ($this->tool)();
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['count']);
        $this->assertCount(2, $data['items']);
    }

    /**
     * list поддерживает поиск по label/description и пагинацию.
     */
    public function testListSearchAndPagination(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $storage = new StoreStorage($this->tmpDir . '/.store');
        $storage->save($sessionKey, 'alpha', '1', 'first');
        $storage->save($sessionKey, 'beta', '2', 'second');
        $storage->save($sessionKey, 'gamma', '3', 'second match');

        $json = ($this->tool)(2, 1, 'second'); // page_size=2, page=1, query=second
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['pageSize']);
        $this->assertSame(1, $data['page']);
        $this->assertSame('second', $data['query']);
        $this->assertSame(2, $data['totalCount']); // beta + gamma
        $this->assertSame(2, $data['count']); // first page gets both
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
