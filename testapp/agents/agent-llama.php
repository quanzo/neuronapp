<?php

/**
 * Пример конфигурации агента
 * 
 * 
 * docker run --gpus all -v ~/gguf:/models -p 11435:8080 ghcr.io/ggml-org/llama.cpp:full-cuda --server -m /models/OmniCoder-Claude-uncensored-V2-Q4_K_M.gguf --port 8080 --host 0.0.0.0 --n-gpu-layers 100
 * 
 * https://huggingface.co/Ngixdev/OmniCoder-Qwen3.5-9B-Claude-4.6-Opus-Uncensored-v2-GGUF
 * 
 * 
 * docker run --gpus all -v ~/gguf:/models -p 11435:8080 ghcr.io/ggml-org/llama.cpp:full-cuda --server -m /models/Qwen3.5-9B-Abliterated.Q4_K_M.gguf --port 8080 --host 0.0.0.0 --n-gpu-layers 100
 * 
 * docker run --gpus all -v ~/gguf:/models -p 11435:8080 ghcr.io/ggml-org/llama.cpp:full-cuda --server -m /models/Qwen3.5-9B-Q4_K_M.gguf --port 8080 --host 0.0.0.0 --n-gpu-layers 100
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
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\OpenAILike;

$homeDir = getenv('HOME');

if ($homeDir === false || $homeDir === '') {
    $homeDir = $_SERVER['HOME'] ?? '';
}

$url           = 'http://localhost:11435/v1';
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
        /**/
        'class'      => OpenAILike::class,
        'key' => 'not-need',
        'baseUri'        => $url,
        //*/
        /*
        'class'      => Ollama::class,
        'url'        => $url,
        */
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
        'model' => 'OmniCoder-Claude-uncensored-V2-Q4_K_M',
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
    ],

    'skills' => [
        'skill-file-block-summarize',
        'skill-text-finder'
    ],
];
return $ar;
