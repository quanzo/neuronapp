<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\tools\BashTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\ViewTool;
use PHPUnit\Framework\TestCase;

/**
 * Тесты дефолтных директорий для инструментов (tools).
 *
 * Цель: зафиксировать, что инструменты больше не зависят от getcwd() как от «текущей»
 * директории процесса, а используют директорию старта приложения
 * ({@see ConfigurationApp::getStartDir()}), которая задаётся как самая приоритетная
 * директория в {@see DirPriority}.
 */
final class ToolStartDirDefaultsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_tool_startdir_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);
        mkdir($this->tmpDir . '/.mind', 0777, true);

        $this->resetSingleton();

        ConfigurationApp::init(new DirPriority([$this->tmpDir]));
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
        $this->removeDir($this->tmpDir);
    }

    /**
     * Сбрасывает приватное статическое свойство $instance через Reflection.
     */
    private function resetSingleton(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);
    }

    /**
     * Рекурсивное удаление директории.
     */
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
     * Если basePath в инструменте не задан, он берётся из ConfigurationApp::getStartDir()
     * при установке ConfigurationAgent (setAgentCfg()).
     */
    public function testBasePathDefaultsToStartDirViaAgentCfg(): void
    {
        $agentCfg = new ConfigurationAgent();
        $agentCfg->setConfigurationApp(ConfigurationApp::getInstance());

        $tool = new ViewTool(basePath: '');
        $tool->setAgentCfg($agentCfg);

        $this->assertSame(
            $this->tmpDir,
            $this->readObjectStringProperty($tool, 'basePath')
        );
    }

    /**
     * Если workingDirectory в инструменте не задан, он берётся из ConfigurationApp::getStartDir()
     * при установке ConfigurationAgent (setAgentCfg()).
     */
    public function testWorkingDirectoryDefaultsToStartDirViaAgentCfg(): void
    {
        $agentCfg = new ConfigurationAgent();
        $agentCfg->setConfigurationApp(ConfigurationApp::getInstance());

        $tool = new BashTool(workingDirectory: '');
        $tool->setAgentCfg($agentCfg);

        $this->assertSame(
            $this->tmpDir,
            $this->readObjectStringProperty($tool, 'workingDirectory')
        );
    }

    /**
     * Явно заданная директория не должна перетираться «директорией старта».
     */
    public function testExplicitBasePathIsNotOverridden(): void
    {
        $explicit = $this->tmpDir . '/explicit';
        mkdir($explicit, 0777, true);

        $agentCfg = new ConfigurationAgent();
        $agentCfg->setConfigurationApp(ConfigurationApp::getInstance());

        $tool = new GrepTool(basePath: $explicit);
        $tool->setAgentCfg($agentCfg);

        $this->assertSame(
            $explicit,
            $this->readObjectStringProperty($tool, 'basePath')
        );
    }

    /**
     * Читает строковое свойство объекта через Reflection.
     *
     * @param object $obj
     * @param string $propertyName
     *
     * @return string
     */
    private function readObjectStringProperty(object $obj, string $propertyName): string
    {
        $ref = new \ReflectionObject($obj);
        $prop = $ref->getProperty($propertyName);
        $prop->setAccessible(true);
        $val = $prop->getValue($obj);
        $this->assertIsString($val);
        return $val;
    }
}
