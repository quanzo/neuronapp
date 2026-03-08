<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\classes\command\TodolistCommand;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\run\RunStateDto;
use app\modules\neuron\helpers\RunStateCheckpointHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Тесты поведения TodolistCommand при незавершённом чекпоинте в сессии.
 *
 * Проверяется: при запуске с --session_id без --resume/--abort и наличии
 * незавершённого run в неинтерактивном режиме команда завершается с FAILURE
 * и выводит сообщение с подсказкой указать --resume или --abort.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\command\TodolistCommand}
 */
class TodolistCommandUnfinishedCheckpointTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_todolist_uc_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/agents', 0777, true);
        mkdir($this->tmpDir . '/todos', 0777, true);

        file_put_contents(
            $this->tmpDir . '/agents/default.jsonc',
            '{"enableChatHistory":false,"contextWindow":5000}'
        );
        file_put_contents($this->tmpDir . '/todos/list1.md', "1. First task.\n");
        $sessionKey = '20250101-120000-1';
        file_put_contents($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '-default.chat', '[]');
        $this->resetConfigurationAppSingleton();
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);

        $dto = (new RunStateDto())
            ->setSessionKey($sessionKey)
            ->setAgentName('default')
            ->setRunId('run-1')
            ->setTodolistName('list1')
            ->setStartedAt('2025-01-01T12:00:00+00:00')
            ->setLastCompletedTodoIndex(0)
            ->setHistoryMessageCount(2)
            ->setFinished(false);
        RunStateCheckpointHelper::write($dto);
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
     * При незавершённом чекпоинте и неинтерактивном режиме команда возвращает FAILURE
     * и выводит сообщение с подсказкой --resume или --abort.
     */
    public function testNonInteractiveUnfinishedCheckpointReturnsFailureWithMessage(): void
    {
        $command = new TodolistCommand();
        $input = new ArrayInput([
            '--todolist' => 'list1',
            '--agent' => 'default',
            '--session_id' => '20250101-120000-1',
        ]);
        $input->setInteractive(false);

        $output = new BufferedOutput();

        $code = $command->run($input, $output);

        $this->assertSame(Command::FAILURE, $code);
        $display = $output->fetch();
        $this->assertStringContainsString('незавершённое выполнение', $display);
        $this->assertStringContainsString('--resume', $display);
        $this->assertStringContainsString('--abort', $display);
        $this->assertStringContainsString('list1', $display);
    }
}
