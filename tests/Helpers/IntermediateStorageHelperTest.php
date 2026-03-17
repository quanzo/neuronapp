<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\helpers\IntermediateStorageHelper;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see IntermediateStorageHelper}.
 *
 * Проверяют хранение промежуточных результатов в `.store` по связке (sessionKey, label):
 * - save(): запись результата и обновление индекса
 * - load(): чтение результата
 * - list(): получение списка из индекса и fallback-сканирование
 * - exists(): проверка наличия
 * - граничные условия для label
 */
final class IntermediateStorageHelperTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_intermediate_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * Сохраняем JSON-значение: файл результата и индекс должны появиться.
     */
    public function testSaveCreatesResultFileAndIndex(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $item = IntermediateStorageHelper::save($sessionKey, 'requirements', ['a' => 1], 'Требования (минимальный пример)');

        $this->assertSame('requirements', $item->label);
        $this->assertTrue(file_exists(IntermediateStorageHelper::resultFilePath($sessionKey, 'requirements')));
        $this->assertTrue(file_exists(IntermediateStorageHelper::indexFilePath($sessionKey)));
    }

    /**
     * load() возвращает сохранённую структуру и data.
     */
    public function testLoadReturnsSavedData(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        IntermediateStorageHelper::save($sessionKey, 'parsed', ['x' => ['y' => 2]], 'Parsed data');

        $loaded = IntermediateStorageHelper::load($sessionKey, 'parsed');
        $this->assertNotNull($loaded);
        $this->assertSame('parsed', $loaded['label'] ?? null);
        $this->assertSame(['x' => ['y' => 2]], $loaded['data'] ?? null);
    }

    /**
     * exists() должен отражать наличие/отсутствие сохранённого файла.
     */
    public function testExistsTrueAndFalse(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $this->assertFalse(IntermediateStorageHelper::exists($sessionKey, 'missing'));

        IntermediateStorageHelper::save($sessionKey, 'present', 'hello', 'Greeting');
        $this->assertTrue(IntermediateStorageHelper::exists($sessionKey, 'present'));
    }

    /**
     * list() возвращает элементы по индексу после save().
     */
    public function testListReturnsIndexItems(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        IntermediateStorageHelper::save($sessionKey, 'l1', 'a', 'one');
        IntermediateStorageHelper::save($sessionKey, 'l2', 'b', 'two');

        $items = IntermediateStorageHelper::list($sessionKey);
        $labels = array_map(static fn($i) => $i->label, $items);

        $this->assertContains('l1', $labels);
        $this->assertContains('l2', $labels);
    }

    /**
     * list() должен уметь работать без индекса (fallback сканированием `.store`).
     */
    public function testListFallbackWhenIndexMissing(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        IntermediateStorageHelper::save($sessionKey, 'scan_me', ['k' => 1], 'scan fallback');

        @unlink(IntermediateStorageHelper::indexFilePath($sessionKey));

        $items = IntermediateStorageHelper::list($sessionKey);
        $labels = array_map(static fn($i) => $i->label, $items);

        $this->assertContains('scan_me', $labels);
    }

    /**
     * Пустой label запрещён: save/load должны бросать InvalidArgumentException.
     */
    public function testEmptyLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        IntermediateStorageHelper::save($sessionKey, '   ', 'x');
    }

    /**
     * Спецсимволы в label должны безопасно отражаться в имени файла.
     */
    public function testFileNameSanitizesLabel(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $label = 'my label/with:bad*chars';
        IntermediateStorageHelper::save($sessionKey, $label, 'x', 'sanitized');

        $fileName = IntermediateStorageHelper::resultFileName($sessionKey, $label);
        $this->assertStringContainsString('intermediate_', $fileName);
        $this->assertStringEndsWith('.json', $fileName);
        $this->assertTrue(file_exists($this->tmpDir . '/.store/' . $fileName));
    }

    /**
     * load() при отсутствии файла возвращает null.
     */
    public function testLoadMissingReturnsNull(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $this->assertNull(IntermediateStorageHelper::load($sessionKey, 'absent'));
    }

    /**
     * Если индекс-файл повреждён (невалидный JSON), list() должен вернуться к сканированию.
     */
    public function testListFallbackWhenIndexCorrupted(): void
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        IntermediateStorageHelper::save($sessionKey, 'recover', ['v' => 1], 'recover');

        file_put_contents(IntermediateStorageHelper::indexFilePath($sessionKey), '{not json');

        $items = IntermediateStorageHelper::list($sessionKey);
        $labels = array_map(static fn($i) => $i->label, $items);
        $this->assertContains('recover', $labels);
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
