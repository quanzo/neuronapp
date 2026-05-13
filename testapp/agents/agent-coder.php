<?php

/**
 * Кодер со всеми инструментами
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\helpers\ShellToolFactory;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\VarGetTool;
use app\modules\neuron\tools\VarListTool;
use app\modules\neuron\tools\VarPadTool;
use app\modules\neuron\tools\VarSetTool;
use app\modules\neuron\tools\ViewTool;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\MCP\McpConnector;


$prompt = include (__DIR__ . '/../prompts/system/coder.php');
$ar     = include __DIR__ . '/models/base.php';

$ar['instructions'] = [
    CallableWrapper::class,
    'createObject',
    'class'      => SystemPrompt::class,
    'background' => [
        'Твоё имя: Ковальски',
        $prompt
    ],
];

$ar['tools'] = [
    [VarGetTool::class, 'make'],
    [VarSetTool::class, 'make'],
    [VarListTool::class, 'make'],
    [VarPadTool::class, 'make'],
    [ViewTool::class, 'make'],

    [
        CallableWrapper::class,
        'call',
        'call' => [
            // фабрика возвращает объект инструмента
            ShellToolFactory::class,
            'createReadonlyBashCmdTool',
            'commandTemplate'  => 'git status',
            'workingDirectory' => dirname(__DIR__, 2),
            'name'             => 'git_status',
            'description'      => 'Статус git'
        ],
    ],

    // Поиск по содержимому файлов
    [
        CallableWrapper::class,
        'createObject',
        'class'        => GrepTool::class,
        //'basePath'     => dirname(__DIR__, 2),
        'maxMatches'   => 200,
        'maxFileSize'  => 2 * 1024 * 1024,
        'excludePatterns' => ['.git', 'temp'],
    ],

    // Поиск файлов по маске
    [
        CallableWrapper::class,
        'createObject',
        'class'        => GlobTool::class,
        //'basePath'     => dirname(__DIR__, 2),
        'maxResults'   => 2000,
        'excludePatterns' => ['.git', 'temp'],
    ],

    [
        CallableWrapper::class,
        'createObject',
        'class'  => ShellToolFactory::class,
        'method' => 'createReadonlyBashCmdTool',
        'args'   => [
            'git status --short --branch',
            dirname(__DIR__, 2),
            'git_status_short',
            'Получает краткий статус git-репозитория (ветка и изменённые файлы).',
        ],
    ],

    [
        CallableWrapper::class,
        'createObject',
        'class'  => ShellToolFactory::class,
        'method' => 'createReadonlyBashCmdTool',
        'args'   => [
            'composer show --no-interaction --no-ansi',
            dirname(__DIR__, 2),
            'composer_show',
            'Краткий обзор установленных composer-зависимостей.',
        ],
    ],

    [
        CallableWrapper::class,
        'createObject',
        'class'  => ShellToolFactory::class,
        'method' => 'createReadonlyBashCmdTool',
        'args'   => [
            'php -v',
            dirname(__DIR__, 2),
            'php_version',
            'Выводит версию PHP, используемую в среде исполнения.',
        ],
    ],
];

$ar['mcp'] = [
    [
        // Поиск
        CallableWrapper::class,
        'createObject',
        'class'  => McpConnector::class,
        'config' => [
            'command' => 'bash',
            'args' => [
                __DIR__ . '/mcp-open-websearch'
            ],
            'env' => [
                "DEFAULT_SEARCH_ENGINE"  => "duckduckgo",
                "ALLOWED_SEARCH_ENGINES" => "duckduckgo,exa,baidu,bing,linuxdo,csdn,brave",
                "PORT"                   => "5051",
                "MODE"                   => "stdio",
            ]
        ]
    ],

    [
        // Context7
        CallableWrapper::class,
        'createObject',
        'class'   => McpConnector::class,
        'config' => [
            'url'     => 'https://mcp.context7.com/mcp',
            'async'   => false,
            'timeout' => 10,
            //"headers" => ["CONTEXT7_API_KEY" => "ctx7sk-7010e527-1111-4d81-983e-1111111"],
        ]
    ],
];

return $ar;
