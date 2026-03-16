<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\tools\IntermediateSaveTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see IntermediateSaveTool}.
 *
 * Проверяют:
 * - успешное сохранение JSON-структуры
 * - успешное сохранение plain-строки
 * - ошибку при пустом label
 */
final class IntermediateSaveToolTest extends TestCase
{
    private string $tmpDir;
    private IntermediateSaveTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_intermediate_save_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');

        $this->tool = new IntermediateSaveTool();
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * Сохранение JSON-строки должно помечать операцию как успешную.
     */
    public function testSaveJson(): void
    {
        $json = ($this->tool)('parsed', '{"x":[1,2]}');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame('save', $data['action']);
        $this->assertSame('parsed', $data['label']);
    }

    /**
     * Пустой label должен приводить к ошибке.
     */
    public function testEmptyLabelReturnsError(): void
    {
        $json = ($this->tool)('   ', '{"a":1}');
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
