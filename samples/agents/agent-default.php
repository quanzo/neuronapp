<?php

/**
 * Пример конфигурации агента "default" — универсальный ассистент.
 * Скопируйте в agents/default.php для использования.
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\tools\ChatHistoryMessageTool;
use app\modules\neuron\tools\ChatHistoryMetaTool;
use app\modules\neuron\tools\ChatHistorySizeTool;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Agent\SystemPrompt;

return [
    'enableChatHistory' => true,
    'contextWindow' => 50000,
    'toolMaxTries' => 5,

    'provider' => [
        CallableWrapper::class,
        'createObject',
        'class' => Ollama::class,
        'url' => 'http://localhost:11434/api',
        'parameters' => [
            'options' => [
                'temperature' => 0.3,
                'top_p' => 0.9,
                'repeat_penalty' => 1.1,
            ],
        ],
        'model' => 'llama3.2:latest',
    ],

    'instructions' => [
        CallableWrapper::class,
        'createObject',
        'class' => SystemPrompt::class,
        'background' => [
            'Ты полезный и вежливый ассистент.',
            'Отвечай кратко и по делу. Приоритетный язык: русский.',
        ],
        'steps' => ['Выполняй запрос пользователя.'],
        'output' => ['Дай ответ.'],
    ],

    'tools' => [
        [ChatHistorySizeTool::class, 'make'],
        [ChatHistoryMetaTool::class, 'make'],
        [ChatHistoryMessageTool::class, 'make'],
    ],
];
