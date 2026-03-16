<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\helpers\IntermediateStorageHelper;
use app\modules\neuron\tools\IntermediateLoadTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see IntermediateLoadTool}.
 *
 * Проверяют:
 * - успешную загрузку ранее сохранённого значения
 * - корректную ошибку при отсутствии метки
 */
final class IntermediateLoadToolTest extends TestCase
{
    private string $tmpDir;
    private IntermediateLoadTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_intermediate_load_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->tool = new IntermediateLoadTool();
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * При наличии сохранённого значения load должен вернуть success=true и данные.
     */
    public function testLoadExisting(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        IntermediateStorageHelper::save($sessionKey, 'parsed', ['x' => 1]);

        $json = ($this->tool)('parsed');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame('load', $data['action']);
        $this->assertSame(['x' => 1], $data['data']);
    }

    /**
     * Отсутствующее значение должно давать success=false и exists=false.
     */
    public function testLoadMissing(): void
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
