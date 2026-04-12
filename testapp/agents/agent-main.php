<?php

/**
 * Пример конфигурации агента
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\tools\GlobTool;
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
$prompt        = include (__DIR__ . '/../prompts/system/base.php');

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
        //'model' => 'qwen3.5:9b',
        'model' => 'qwen3.5:cloud',
        //'model' => 'gemma4:e4b',
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
        //[GlobTool::class, 'make'],
        //[ViewTool::class, 'make'],
    ],

    'skills' => [
        'skill-file-block-summarize',
        'skill-text-finder'
    ],
];
return $ar;
