<?php

/**
 * Пример конфигурации пустого агента 
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\classes\neuron\providers\EchoProvider;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\MCP\McpConnector;

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

    /**/
    'mcp' => [
        [
            // Context7
            CallableWrapper::class,
            'createObject',
            'class'   => McpConnector::class,
            'config' => [
                'url'     => 'https://mcp.context7.com/mcp',
                'async'   => false,
                'timeout' => 10,
                //"headers" => ["CONTEXT7_API_KEY" => "ctx7sk-7010e527-1111-4d81-983e-1111111"],
            ]
        ]
    ],

    'tools' => [],
];
