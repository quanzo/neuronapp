<?php

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\helpers\ShellToolFactory;
use app\modules\neuron\tools\ViewTool;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Ollama\Ollama;

$contextWindow = 16000;

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
            'timeout'        => 45.0,
            'connectTimeout' => 10.0,
        ],
        'parameters' => [
            'options' => [
                'temperature'    => 0.25,
                'top_p'          => 0.9,
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
            'Ты агент обслуживания проекта: формируешь дайджесты, краткие отчёты и проверяешь базовое состояние репозитория.',
            'Отвечай на русском, структурируй вывод: сначала кратко, затем детали.',
            'Используй логи и заметки как основной источник информации; команды git и composer используй только для сводки статуса.',
        ],
        'steps' => [
            'Собери входные данные: запрос пользователя, заметки, логи.',
            'При необходимости просмотри свежие логи и заметки из рабочей директории.',
            'Сформируй краткий дайджест: что произошло, текущее состояние, риски.',
            'Сформулируй конкретные следующие шаги для пользователя.',
        ],
        'output' => [
            'Ответ структурируй в разделы: "Входные данные", "Анализ", "Рекомендации".',
        ],
    ],

    'tools' => [
        // Чтение логов и заметок в пределах APP_WORK_DIR (testapp)
        [
            CallableWrapper::class,
            'createObject',
            'class'     => ViewTool::class,
            'basePath'  => dirname(__DIR__, 1), // testapp
            'maxLines'  => 2000,
            'name'      => 'view_app_files',
            'description' => 'Чтение файлов логов и заметок внутри рабочей директории приложения (testapp).',
        ],
        // Предопределённые readonly-команды через ShellToolFactory
        [
            CallableWrapper::class,
            'createObject',
            'class'  => ShellToolFactory::class,
            'method' => 'createReadonlyBashCmdTool',
            'args'   => [
                'git status --short --branch',
                dirname(__DIR__, 2),
                'git_status_short',
                'Получает краткий статус git-репозитория (ветка и изменённые файлы).',
            ],
        ],
        [
            CallableWrapper::class,
            'createObject',
            'class'  => ShellToolFactory::class,
            'method' => 'createReadonlyBashCmdTool',
            'args'   => [
                'composer show --no-interaction --no-ansi',
                dirname(__DIR__, 2),
                'composer_show',
                'Краткий обзор установленных composer-зависимостей.',
            ],
        ],
        [
            CallableWrapper::class,
            'createObject',
            'class'  => ShellToolFactory::class,
            'method' => 'createReadonlyBashCmdTool',
            'args'   => [
                'php -v',
                dirname(__DIR__, 2),
                'php_version',
                'Выводит версию PHP, используемую в среде исполнения.',
            ],
        ],
    ],
];

