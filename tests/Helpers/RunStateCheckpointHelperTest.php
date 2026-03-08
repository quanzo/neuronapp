<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\run\RunStateDto;
use app\modules\neuron\helpers\RunStateCheckpointHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see RunStateCheckpointHelper}.
 *
 * RunStateCheckpointHelper — чтение/запись/удаление чекпоинтов состояния run
 * в .store/run_state_{sessionKey}_{agentName}.json с атомарной записью.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\RunStateCheckpointHelper}
 */
class RunStateCheckpointHelperTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_checkpoint_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
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

    /**
     * read() при отсутствии файла возвращает null.
     */
    public function testReadWhenFileMissingReturnsNull(): void
    {
        $result = RunStateCheckpointHelper::read('20250308-120000-1', 'default');
        $this->assertNull($result);
    }

    /**
     * write() создаёт файл, read() возвращает эквивалентный DTO.
     */
    public function testWriteThenRead(): void
    {
        $dto = (new RunStateDto())
            ->setSessionKey('20250308-120000-1')
            ->setAgentName('default')
            ->setRunId('run-1')
            ->setTodolistName('code-review')
            ->setStartedAt('2025-03-08T12:00:00+00:00')
            ->setLastCompletedTodoIndex(0)
            ->setHistoryMessageCount(5)
            ->setFinished(false);

        RunStateCheckpointHelper::write($dto);

        $read = RunStateCheckpointHelper::read('20250308-120000-1', 'default');
        $this->assertInstanceOf(RunStateDto::class, $read);
        $this->assertSame($dto->getSessionKey(), $read->getSessionKey());
        $this->assertSame($dto->getAgentName(), $read->getAgentName());
        $this->assertSame($dto->getLastCompletedTodoIndex(), $read->getLastCompletedTodoIndex());
        $this->assertSame($dto->getHistoryMessageCount(), $read->getHistoryMessageCount());
        $this->assertSame($dto->isFinished(), $read->isFinished());
    }

    /**
     * delete() удаляет файл; последующий read() возвращает null.
     */
    public function testDeleteRemovesFile(): void
    {
        $dto = (new RunStateDto())
            ->setSessionKey('s1')
            ->setAgentName('a1')
            ->setRunId('r1')
            ->setTodolistName('t1')
            ->setStartedAt('2025-01-01T00:00:00+00:00')
            ->setLastCompletedTodoIndex(-1)
            ->setFinished(false);
        RunStateCheckpointHelper::write($dto);
        $this->assertNotNull(RunStateCheckpointHelper::read('s1', 'a1'));

        RunStateCheckpointHelper::delete('s1', 'a1');
        $this->assertNull(RunStateCheckpointHelper::read('s1', 'a1'));
    }

    /**
     * delete() при отсутствии файла не бросает исключения.
     */
    public function testDeleteWhenFileMissingNoError(): void
    {
        RunStateCheckpointHelper::delete('nonexistent', 'agent');
        $this->assertNull(RunStateCheckpointHelper::read('nonexistent', 'agent'));
    }

    /**
     * fileName() формирует безопасное имя; спецсимволы заменяются на подчёркивание.
     */
    public function testFileNameSanitizes(): void
    {
        $name = RunStateCheckpointHelper::fileName('20250308-120000-1', 'default');
        $this->assertSame('run_state_20250308-120000-1_default.json', $name);
    }

    /**
     * Запись поверх существующего файла перезаписывает содержимое (последнее значение читается).
     */
    public function testWriteOverwrites(): void
    {
        $dto1 = (new RunStateDto())
            ->setSessionKey('k')
            ->setAgentName('a')
            ->setRunId('r1')
            ->setTodolistName('t')
            ->setStartedAt('2025-01-01T00:00:00+00:00')
            ->setLastCompletedTodoIndex(0)
            ->setFinished(false);
        RunStateCheckpointHelper::write($dto1);

        $dto2 = (new RunStateDto())
            ->setSessionKey('k')
            ->setAgentName('a')
            ->setRunId('r2')
            ->setTodolistName('t')
            ->setStartedAt('2025-01-01T00:00:00+00:00')
            ->setLastCompletedTodoIndex(2)
            ->setHistoryMessageCount(10)
            ->setFinished(false);
        RunStateCheckpointHelper::write($dto2);

        $read = RunStateCheckpointHelper::read('k', 'a');
        $this->assertSame(2, $read->getLastCompletedTodoIndex());
        $this->assertSame(10, $read->getHistoryMessageCount());
    }

    /**
     * read() при невалидном JSON в файле возвращает null (не бросает).
     */
    public function testReadInvalidJsonReturnsNull(): void
    {
        $path = RunStateCheckpointHelper::filePath('k', 'a');
        file_put_contents($path, 'not json {');
        $result = RunStateCheckpointHelper::read('k', 'a');
        $this->assertNull($result);
    }
}
