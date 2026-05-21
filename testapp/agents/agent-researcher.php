<?php

use app\modules\neuron\helpers\CallableWrapper;
use NeuronAI\Agent\SystemPrompt;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\VarGetTool;
use app\modules\neuron\tools\VarListTool;
use app\modules\neuron\tools\VarPadTool;
use app\modules\neuron\tools\VarSetTool;
use app\modules\neuron\tools\ViewTool;
use NeuronAI\MCP\McpConnector;

$ar     = include __DIR__ . '/models/base.php';
$prompt = include (__DIR__ . '/../prompts/system/researcher.php');

$ar['instructions'] = [
    CallableWrapper::class,
    'createObject',
    'class'      => SystemPrompt::class,
    'background' => [
        $prompt
    ],
];


$ar['tools'] = [
    [VarGetTool::class, 'make'],
    [VarSetTool::class, 'make'],
    [VarListTool::class, 'make'],
    [VarPadTool::class, 'make'],
    [ViewTool::class, 'make'],

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

    // Универсальный поиск по Wikipedia (ru/en)
    /*
    [
        CallableWrapper::class,
        'createObject',
        'class'       => WikiSearchTool::class,
        'name'        => 'wiki_search',
        'description' => 'Поиск и загрузка статей из Wikipedia (ru/en).',
    ],
    // Специализированный поиск по российской RuWiki
    [
        CallableWrapper::class,
        'createObject',
        'class'       => RuWikiSearchTool::class,
        'name'        => 'ru_wiki_search',
        'description' => 'Поиск и загрузка статей из российской RuWiki.',
    ],
    // Универсальный поисковый инструмент (по умолчанию Wikipedia ru/en)
    [
        CallableWrapper::class,
        'createObject',
        'class'       => UniSearchTool::class,
        'name'        => 'uni_search',
        'description' => 'Общий инструмент поиска определений и справочной информации.',
    ],
    // Поиск web‑страниц через Ollama Web Search (если сконфигурирован API-ключ)
    /*
    [
        CallableWrapper::class,
        'createObject',
        'class'       => OllamaSearchTool::class,
        'ollamaApiKey'=> '', // при необходимости передать ключ
    ],
    */
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
];

return $ar;