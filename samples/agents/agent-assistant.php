<?php

/**
 * Пример конфигурации агента "assistant" — ассистент с калькулятором.
 * Скопируйте в agents/assistant.php для использования.
 */

use app\modules\neuron\helpers\CallableWrapper;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\SystemPrompt;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;

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
                'temperature' => 0.2,
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
            'Ты ассистент с доступом к калькулятору и текущей дате/времени.',
            'Используй инструменты для вычислений и уточнения времени. Язык: русский.',
        ],
        'steps' => ['Выполни задачу пользователя, при необходимости используя инструменты.'],
        'output' => ['Представь результат.'],
    ],

    'tools' => [
        [CalculatorToolkit::class, 'make'],
        [CurrentDateTimeTool::class, 'make'],
    ],
];
