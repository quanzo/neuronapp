<?php

/**
 * Пример конфигурации агента "analyst" — аналитик данных.
 * Скопируйте в agents/analyst.php для использования.
 */

use app\modules\neuron\helpers\CallableWrapper;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\SystemPrompt;

return [
    'enableChatHistory' => true,
    'contextWindow' => 80000,
    'toolMaxTries' => 8,

    'provider' => [
        CallableWrapper::class,
        'createObject',
        'class' => Ollama::class,
        'url' => 'http://localhost:11434/api',
        'parameters' => [
            'options' => [
                'temperature' => 0.1,
                'top_p' => 0.9,
                'repeat_penalty' => 1.15,
            ],
        ],
        'model' => 'llama3.2:latest',
    ],

    'instructions' => [
        CallableWrapper::class,
        'createObject',
        'class' => SystemPrompt::class,
        'background' => [
            'Ты аналитик данных. Делай выводы только на основе проверенных данных.',
            'Если данных недостаточно — сформулируй, что нужно уточнить.',
            'Приоритетный язык: русский. Структурируй ответы: списки, выводы, рекомендации.',
        ],
        'steps' => [
            'Получи задание от пользователя.',
            'Проанализируй данные и сформулируй выводы.',
        ],
        'output' => ['Представь анализ и рекомендации.'],
    ],

    'tools' => [],
];
