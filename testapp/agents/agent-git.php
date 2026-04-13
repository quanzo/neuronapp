<?php

/**
 * Агент для работы с git
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\tools\BashTool;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\VarGetTool;
use app\modules\neuron\tools\VarListTool;
use app\modules\neuron\tools\VarPadTool;
use app\modules\neuron\tools\VarSetTool;
use app\modules\neuron\tools\ViewTool;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\GuzzleHttpClient;

$homeDir = getenv('HOME');

if ($homeDir === false || $homeDir === '') {
    $homeDir = $_SERVER['HOME'] ?? '';
}

$url           = 'http://localhost:11434/api';
$contextWindow = 8192 * 4 * 2 * 2;
$prompt        = include (__DIR__ . '/../prompts/system/git.php');

$ar = [
    'enableChatHistory' => true,
    'contextWindow'     => $contextWindow,
    'toolMaxTries'      => 75,
    'llmPayloadLogMode' => 'summary',

    'provider' => [
        CallableWrapper::class,
        'createObject',
        'class'      => Ollama::class,
        'url'        => $url,
        'httpClient' => [
            CallableWrapper::class,
            'createObject',
            'class'          => GuzzleHttpClient::class,
            'timeout'        => 75.0,
            'connectTimeout' => 10.0,
        ],
        'parameters' => [
            'options' => [
                'temperature'    => 0.2,
                'top_p'          => 0.95,
                'repeat_penalty' => 1.1,
                'num_ctx'        => $contextWindow,
                'think'          => false,
            ],
        ],
        'model' => 'qwen3.5:9b',
    ],

    'instructions' => [
        CallableWrapper::class,
        'createObject',
        'class'      => SystemPrompt::class,
        'background' => [
            $prompt
        ],
    ],

    'tools' => [
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

        // работа с git
        [
            CallableWrapper::class,
            'createObject',
            'class'        => BashTool::class,
            'name' => 'git',
            'description' => 'Работа с репозиторием git',
            'allowedPatterns' => [
                '/^git /i'
            ],
            'blockedPatterns' => [
                '/^sudo /',
                '/^rm /',
            ],
        ],

    ],
];
return $ar;
