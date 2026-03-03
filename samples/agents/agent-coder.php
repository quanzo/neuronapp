<?php

/**
 * Пример конфигурации агента "coder" — помощник по программированию.
 * Скопируйте в agents/coder.php для использования.
 */

use app\modules\neuron\helpers\CallableWrapper;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Agent\SystemPrompt;

return [
    'enableChatHistory' => true,
    'contextWindow' => 100000,
    'toolMaxTries' => 5,

    'provider' => [
        CallableWrapper::class,
        'createObject',
        'class' => Ollama::class,
        'url' => 'http://localhost:11434/api',
        'parameters' => [
            'options' => [
                'temperature' => 0.2,
                'top_p' => 0.95,
                'repeat_penalty' => 1.1,
            ],
        ],
        'model' => 'codellama:latest',
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

    'tools' => [],
];
