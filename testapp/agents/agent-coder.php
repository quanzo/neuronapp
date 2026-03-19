<?php

/**
 * Пример конфигурации агента "coder" — помощник по программированию.
 * Скопируйте в agents/coder.php для использования.
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\helpers\ShellToolFactory;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\IntermediateDeleteTool;
use app\modules\neuron\tools\IntermediateExistTool;
use app\modules\neuron\tools\IntermediateListTool;
use app\modules\neuron\tools\IntermediateLoadTool;
use app\modules\neuron\tools\IntermediatePadTool;
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

$contextWindow = 8192 * 4;

$url = 'http://localhost:11434/api';

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
            'timeout'        => 1800.0,
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
        //'model' => 'nemotron-3-nano:30b-cloud',
        'model' => 'qwen3.5:9b',
    ],

    'instructions' => [
        CallableWrapper::class,
        'createObject',
        'class' => SystemPrompt::class,
        'background' => [
            'Ты помощник программиста. Пиши чистый, документированный код.',
            'Отвечай на русском, код и идентификаторы — на английском.',
            'Предлагай краткие объяснения и альтернативы при необходимости.',
        ],
        'steps' => [
            'Пойми задачу пользователя.',
            'Предложи решение с кодом или пошаговой инструкцией.',
        ],
        'output' => ['Выведи код и пояснения.'],
    ],

    'tools' => [
        [CurrentDateTimeTool::class, 'make'],
        [ChatHistorySizeTool::class, 'make'],
        [ChatHistoryMetaTool::class, 'make'],
        [ChatHistoryMessageTool::class, 'make'],
        /*
        [FactorialTool::class, 'make'],
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
        */
        [GlobTool::class, 'make'],
        [GrepTool::class, 'make'],
        [ViewTool::class, 'make'],

        [IntermediatePadTool::class, 'make'],
        [IntermediateListTool::class, 'make'],
        [IntermediateExistTool::class, 'make'],
        [IntermediateDeleteTool::class, 'make'],
        [IntermediateLoadTool::class, 'make'],

    ],

    /**/
    'mcp' => [
        /*
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
        */

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
