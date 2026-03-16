<?php

/**
 * Пример конфигурации пустого агента 
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\classes\neuron\providers\EchoProvider;
use NeuronAI\Agent\SystemPrompt;

return [
    'enableChatHistory' => true,
    'contextWindow'     => 80000,
    'toolMaxTries'      => 8,

    'provider' => [
        CallableWrapper::class,
        'createObject',
        'class' => EchoProvider::class,
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
