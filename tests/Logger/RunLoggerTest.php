<?php

declare(strict_types=1);

namespace Tests\Logger;

use app\modules\neuron\classes\logger\FileLogger;
use app\modules\neuron\classes\logger\RunLogger;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see RunLogger}.
 */
class RunLoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/neuronapp_runlogger_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    public function testStartAndFinishRunSuccessWritesLog(): void
    {
        $fileLogger = new FileLogger($this->logFile);
        $runLogger = new RunLogger($fileLogger);

        $runId = $runLogger->startRun('todolist', 'demo', ['agent' => 'test', 'session' => 's1']);
        $this->assertNotSame('', $runId);

        $runLogger->finishRun($runId, ['steps' => 3, 'toolCalls' => 1], null);

        $contents = file_get_contents($this->logFile);
        $this->assertIsString($contents);
        $this->assertStringContainsString('Run started', $contents);
        $this->assertStringContainsString('Run finished successfully', $contents);
        $this->assertStringContainsString($runId, $contents);
    }

    public function testFinishRunWithErrorWritesErrorEntry(): void
    {
        $fileLogger = new FileLogger($this->logFile);
        $runLogger = new RunLogger($fileLogger);

        $runId = $runLogger->startRun('skill', 'demo-skill');
        $error = new \RuntimeException('fail');
        $runLogger->finishRun($runId, ['steps' => 1], $error);

        $contents = file_get_contents($this->logFile);
        $this->assertIsString($contents);
        $this->assertStringContainsString('Run finished with error', $contents);
        $this->assertStringContainsString('fail', $contents);
    }
}

