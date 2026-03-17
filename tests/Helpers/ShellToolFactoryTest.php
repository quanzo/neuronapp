<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\ShellToolFactory;
use app\modules\neuron\tools\BashTool;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see ShellToolFactory}.
 *
 * Проверяются базовые профили:
 *  - createReadonlyBashTool()
 *  - createDiagnosticsBashTool()
 */
class ShellToolFactoryTest extends TestCase
{
    /**
     * Профиль readonly создаёт BashTool и пропускает безопасные команды.
     */
    public function testCreateReadonlyBashToolAllowsSafeCommands(): void
    {
        $tool = ShellToolFactory::createReadonlyBashTool(sys_get_temp_dir());
        $this->assertInstanceOf(BashTool::class, $tool);

        $resultJson = $tool->__invoke('git status --short --branch', 1);
        $this->assertIsString($resultJson);
    }

    /**
     * Профиль readonly блокирует заведомо небезопасные команды.
     */
    public function testCreateReadonlyBashToolBlocksDangerousCommands(): void
    {
        $tool = ShellToolFactory::createReadonlyBashTool(sys_get_temp_dir());

        $resultJson = $tool->__invoke('rm -rf /', 1);
        $this->assertStringContainsString('Команда заблокирована правилом безопасности', $resultJson);
    }

    /**
     * Профиль diagnostics создаёт BashTool и позволяет диагностические команды.
     */
    public function testCreateDiagnosticsBashToolAllowsDiagnostics(): void
    {
        $tool = ShellToolFactory::createDiagnosticsBashTool(sys_get_temp_dir());
        $this->assertInstanceOf(BashTool::class, $tool);

        $resultJson = $tool->__invoke('php -m', 1);
        $this->assertIsString($resultJson);
    }
}
