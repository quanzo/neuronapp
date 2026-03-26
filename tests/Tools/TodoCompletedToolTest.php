<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\tools\TodoCompletedTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты инструмента {@see TodoCompletedTool}.
 *
 * Проверяют:
 * - корректную нормализацию статусов в completed=1/0;
 * - обработку невалидного статуса;
 * - запись значения в store storage.
 */
final class TodoCompletedToolTest extends TestCase
{
    private string $tmpDir;
    private TodoCompletedTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_todo_completed_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]));
        ConfigurationApp::getInstance()->setSessionKey('20250101-120000-1');
        $this->tool = new TodoCompletedTool();
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * Проверяет, что множество валидных входных статусов правильно нормализуются.
     */
    #[DataProvider('provideValidStatuses')]
    public function testValidStatuses(string $status, int $expected): void
    {
        $json = ($this->tool)($status, 'status test');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame('todo_completed', $data['action']);
        $this->assertSame($expected, $data['data']);
        $this->assertSame('completed', $data['name']);

        $payload = ConfigurationApp::getInstance()
            ->getVarStorage()
            ->load(ConfigurationApp::getInstance()->getSessionKey(), 'completed');
        $this->assertIsArray($payload);
        $this->assertSame($expected, $payload['data']);
    }

    /**
     * Невалидный статус должен вернуть success=false.
     */
    public function testInvalidStatusReturnsError(): void
    {
        $json = ($this->tool)('unknown-status', 'invalid test');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertSame('todo_completed', $data['action']);
        $this->assertStringContainsString('Некорректный status', $data['message']);
    }

    /**
     * Набор >=10 кейсов, включая русские строки.
     *
     * @return array<string,array{0:string,1:int}>
     */
    public static function provideValidStatuses(): array
    {
        return [
            'done' => ['done', 1],
            'one' => ['1', 1],
            'true' => ['true', 1],
            'ru_done' => ['исполнено', 1],
            'done_with_spaces' => ['  done  ', 1],
            'not_done' => ['not_done', 0],
            'zero' => ['0', 0],
            'false' => ['false', 0],
            'ru_not_done' => ['не исполнено', 0],
            'ru_not_done_compact' => ['неисполнено', 0],
        ];
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
