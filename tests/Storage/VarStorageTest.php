<?php

declare(strict_types=1);

namespace Tests\Storage;

use app\modules\neuron\classes\dto\tools\VarIndexItemDto;
use app\modules\neuron\classes\storage\VarStorage;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see VarStorage}.
 */
final class VarStorageTest extends TestCase
{
    private string $tmpDir;
    private VarStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_var_storage_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $this->storage = new VarStorage($this->tmpDir . '/.store');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testSaveAndLoad(): void
    {
        $item = $this->storage->save('s1', 'label1', ['a' => 1], 'Example data');
        $this->assertSame('label1', $item->label);
        $this->assertSame('Example data', $item->description);

        $loaded = $this->storage->load('s1', 'label1');
        $this->assertNotNull($loaded);
        $this->assertSame(['a' => 1], $loaded['data'] ?? null);
    }

    public function testExistsAndDelete(): void
    {
        $this->assertFalse($this->storage->exists('s1', 'x'));

        $this->storage->save('s1', 'x', 'v', 'x value');
        $this->assertTrue($this->storage->exists('s1', 'x'));

        $this->storage->delete('s1', 'x');
        $this->assertFalse($this->storage->exists('s1', 'x'));
    }

    public function testListUsesIndex(): void
    {
        $this->storage->save('s1', 'a', '1', 'one');
        $this->storage->save('s1', 'b', '2', 'two');

        $items = $this->storage->list('s1');
        $labels = array_map(static fn(VarIndexItemDto $i) => $i->label, $items);

        $this->assertContains('a', $labels);
        $this->assertContains('b', $labels);
    }

    public function testListFallbackWhenIndexCorrupted(): void
    {
        $this->storage->save('s1', 'recover', ['v' => 1], 'recover');
        $indexPath = $this->tmpDir . '/.store/' . 'var_index_' . 's1' . '.json';
        file_put_contents($indexPath, '{not json');

        $items = $this->storage->list('s1');
        $labels = array_map(static fn(VarIndexItemDto $i) => $i->label, $items);
        $this->assertContains('recover', $labels);
    }

    public function testResultFileNameSanitizes(): void
    {
        $fileName = $this->storage->resultFileName('20250308-120000-1', 'with bad/chars');
        $this->assertStringContainsString('var_', $fileName);
        $this->assertStringEndsWith('.json', $fileName);
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
