<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\tools\BashCmdTool;
use app\modules\neuron\tools\BashTool;

/**
 * Фабрика безопасных профилей Bash-инструментов.
 *
 * Содержит готовые конфигурации для:
 *  - «только чтение» (git status/diff, ls, php -v, composer show);
 *  - «диагностика окружения» (php -m, composer validate и т.п.).
 *
 * Все профили используют белые/чёрные списки regex-шаблонов и
 * консервативные значения таймаутов и ограничения вывода.
 */
final class ShellToolFactory
{
    /**
     * Создаёт BashTool с профилем «только чтение».
     *
     * Разрешены команды:
     *  - git status / git diff;
     *  - ls;
     *  - php -v;
     *  - composer show.
     *
     * Запрещены команды с очевидно разрушающим эффектом (rm -rf и др.).
     */
    public static function createReadonlyBashTool(string $workingDirectory): BashTool
    {
        return new BashTool(
            defaultTimeout: 30,
            maxOutputSize: 102_400,
            workingDirectory: $workingDirectory,
            allowedPatterns: [
                '/^git\\s+status\\b/',
                '/^git\\s+diff\\b/',
                '/^ls(\\s|$)/',
                '/^php\\s+-v$/',
                '/^php\\s+composer\\.phar\\s+show\\b/',
                '/^composer\\s+show\\b/',
            ],
            blockedPatterns: [
                '/rm\\s+-rf/',
                '/:\\s*>/',
            ],
            env: [],
            name: 'bash_readonly',
            description: 'Безопасное выполнение ограниченного набора shell-команд (git status/diff, ls, php -v, composer show).',
        );
    }

    /**
     * Создаёт BashTool с профилем «диагностика окружения».
     *
     * Разрешены команды:
     *  - php -m / php -i;
     *  - composer validate;
     *  - env.
     */
    public static function createDiagnosticsBashTool(string $workingDirectory): BashTool
    {
        return new BashTool(
            defaultTimeout: 40,
            maxOutputSize: 102_400,
            workingDirectory: $workingDirectory,
            allowedPatterns: [
                '/^php\\s+-m$/',
                '/^php\\s+-i$/',
                '/^composer\\s+validate\\b/',
                '/^env(\\s|$)/',
            ],
            blockedPatterns: [
                '/rm\\s+-rf/',
            ],
            env: [],
            name: 'bash_diagnostics',
            description: 'Диагностические shell-команды без модификации окружения (php -m/-i, composer validate, env).',
        );
    }

    /**
     * Создаёт преднастроенный BashCmdTool на основе безопасного BashTool.
     *
     * Удобно для шаблонных команд (git status, composer show, php -v).
     */
    public static function createReadonlyBashCmdTool(
        string $commandTemplate,
        string $workingDirectory,
        string $name,
        string $description
    ): BashCmdTool {
        return new BashCmdTool(
            commandTemplate: $commandTemplate,
            name: $name,
            description: $description,
            defaultTimeout: 30,
            maxOutputSize: 65_536,
            workingDirectory: $workingDirectory,
            allowedPatterns: [
                '/^git\\s+status\\b/',
                '/^git\\s+diff\\b/',
                '/^composer\\s+show\\b/',
                '/^php\\s+-v$/',
            ],
            blockedPatterns: [
                '/rm\\s+-rf/',
            ],
            env: [],
        );
    }
}

