<?php

/**
 * Кодер со всеми инструментами
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\helpers\ShellToolFactory;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\VarExistTool;
use app\modules\neuron\tools\VarGetTool;
use app\modules\neuron\tools\VarListTool;
use app\modules\neuron\tools\VarPadTool;
use app\modules\neuron\tools\VarSetTool;
use app\modules\neuron\tools\VarUnsetTool;
use app\modules\neuron\tools\ViewTool;
use app\modules\neuron\tools\ChatHistoryMessageTool;
use app\modules\neuron\tools\ChatHistoryMetaTool;
use app\modules\neuron\tools\ChatHistorySizeTool;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;

$homeDir = getenv('HOME');

if ($homeDir === false || $homeDir === '') {
    $homeDir = $_SERVER['HOME'] ?? '';
}

$url           = 'http://localhost:11434/api';
$contextWindow = 8192 * 4 * 2 * 2;
$prompt        = include (__DIR__ . '/../prompts/system/coder.php');

return [
    'enableChatHistory' => true,
    'contextWindow'     => $contextWindow,
    'toolMaxTries'      => 5,

    'provider' => [
        CallableWrapper::class,
        'createObject',
        'class'      => Ollama::class,
        'url'        => $url,
        'httpClient' => [
            CallableWrapper::class,
            'createObject',
            'class'          => GuzzleHttpClient::class,
            'timeout'        => 120.0,
            'connectTimeout' => 10.0,
        ],
        'parameters' => [
            'options' => [
                'temperature'    => 0.2,
                'top_p'          => 0.95,
                'repeat_penalty' => 1.1,
                'num_ctx'        => $contextWindow,
            ],
        ],
        'model' => 'qwen3.5:9b',
    ],

    'instructions' => [
        CallableWrapper::class,
        'createObject',
        'class' => SystemPrompt::class,
        'background' => [
            $prompt
        ]
    ],

    'tools' => [
        [VarGetTool::class, 'make'],
        [VarSetTool::class, 'make'],
        [VarListTool::class, 'make'],
        [VarPadTool::class, 'make'],

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

        [ViewTool::class, 'make'],

    ],

    'mcp' => [

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

        /*
        [
            // docx и xlsx для llm
            CallableWrapper::class,
            'createObject',
            'class'  => McpConnector::class,
            'config' => [
                'command' => $homeDir . '/.kreuzberg/bin/kreuzberg mcp --transport stdio',
            ]
        ],
        //*/
    ]
    //*/
];
