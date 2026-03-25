<?php

/**
 * Пример конфигурации агента "coder" — помощник по программированию.
 * Скопируйте в agents/coder.php для использования.
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\tools\ChatHistoryMessageTool;
use app\modules\neuron\tools\ChatHistoryMetaTool;
use app\modules\neuron\tools\ChatHistorySizeTool;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;

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
        'model' => 'qwen3.5:9b',
    ],

    'instructions' => [
        CallableWrapper::class,
        'createObject',
        'class' => SystemPrompt::class,
        'background' => [
            'Ты работаешь с историей сообщений. Тебе доступны инструменты чтения истории сообщений чата. История чата - это твоя память и опыт.',
            'Отвечай на русском.',
        ],
    ],

    'tools' => [
        [CurrentDateTimeTool::class, 'make'],

        [ChatHistorySizeTool::class, 'make'],
        [ChatHistoryMetaTool::class, 'make'],
        [ChatHistoryMessageTool::class, 'make'],

        /*
        [StorePadTool::class, 'make'],
        [StoreListTool::class, 'make'],
        [StoreExistTool::class, 'make'],
        [StoreDeleteTool::class, 'make'],
        [StoreLoadTool::class, 'make'],
        */

    ],
];
