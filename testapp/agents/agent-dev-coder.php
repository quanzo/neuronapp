<?php

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\tools\BashTool;
use app\modules\neuron\tools\EditTool;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\UniSearchTool;
use app\modules\neuron\tools\ViewTool;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Ollama\Ollama;

$contextWindow = 32000;

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
            'timeout'        => 60.0,
            'connectTimeout' => 10.0,
        ],
        'parameters' => [
            'options' => [
                'temperature'    => 0.2,
                'top_p'          => 0.95,
                'repeat_penalty' => 1.05,
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
            'Ты помощник разработчика в PHP/JS и смежных технологиях.',
            'Отвечай на русском, код и идентификаторы — на английском.',
            'Всегда сначала изучай существующий код перед правками (через инструменты просмотра/поиска).',
            'Соблюдай договорённости проекта по документации классов и методов.',
        ],
        'steps' => [
            'Пойми задачу пользователя и ограничения (директории, технологии).',
            'Найди релевантные файлы через инструменты поиска и чтения.',
            'Предложи изменения с учётом структуры проекта и стиля.',
            'При необходимости покажи пошаговый план и альтернативные варианты.',
        ],
        'output' => [
            'Дай итоговое решение: краткий план действий и, при необходимости, фрагменты кода.',
        ],
    ],

    'tools' => [
        // Чтение файлов с нумерацией строк
        [
            CallableWrapper::class,
            'createObject',
            'class'     => ViewTool::class,
            'basePath'  => dirname(__DIR__, 2),
            'maxLines'  => 2000,
        ],
        // Поиск по содержимому файлов
        [
            CallableWrapper::class,
            'createObject',
            'class'        => GrepTool::class,
            'basePath'     => dirname(__DIR__, 2),
            'maxMatches'   => 200,
            'maxFileSize'  => 2 * 1024 * 1024,
            'excludePatterns' => ['.git', 'node_modules', 'vendor', 'temp'],
        ],
        // Поиск файлов по маске
        [
            CallableWrapper::class,
            'createObject',
            'class'        => GlobTool::class,
            'basePath'     => dirname(__DIR__, 2),
            'maxResults'   => 2000,
            'excludePatterns' => ['.git', 'node_modules', 'vendor', 'temp'],
        ],
        // Редактирование файлов в пределах репозитория
        [
            CallableWrapper::class,
            'createObject',
            'class'             => EditTool::class,
            'basePath'          => dirname(__DIR__, 2),
            'createBackup'      => true,
            'createIfNotExists' => false,
            'maxFileSize'       => 1024 * 1024,
        ],
        // Безопасные shell-команды
        [
            CallableWrapper::class,
            'createObject',
            'class'           => BashTool::class,
            'defaultTimeout'  => 30,
            'maxOutputSize'   => 102400,
            'workingDirectory'=> dirname(__DIR__, 2),
            'allowedPatterns' => [
                '/^git\\s+status\\b/',
                '/^git\\s+diff\\b/',
                '/^ls(\\s|$)/',
                '/^php\\s+-v$/',
                '/^php\\s+composer\\.phar\\s+show\\b/',
                '/^composer\\s+show\\b/',
            ],
            'blockedPatterns' => [
                '/rm\\s+-rf/',
                '/:\\s*\\>/',
            ],
            'env'             => [],
            'name'            => 'bash_safe',
            'description'     => 'Безопасное выполнение ограниченного набора shell-команд (git status, git diff, ls, php -v, composer show).',
        ],
        // Поиск по Википедии
        [
            CallableWrapper::class,
            'createObject',
            'class'       => UniSearchTool::class,
            'name'        => 'wiki_search',
            'description' => 'Поиск определений и справочной информации в Википедии (ru/en).',
        ],
    ],
];

