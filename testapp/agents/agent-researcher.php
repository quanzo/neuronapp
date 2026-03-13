<?php

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\tools\OllamaSearchTool;
use app\modules\neuron\tools\RuWikiSearchTool;
use app\modules\neuron\tools\UniSearchTool;
use app\modules\neuron\tools\WikiSearchTool;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Ollama\Ollama;

$contextWindow = 64000;

$url = 'http://localhost:11434/api';

return [
    'enableChatHistory' => true,
    'contextWindow'     => $contextWindow,
    'toolMaxTries'      => 6,

    'provider' => [
        CallableWrapper::class,
        'createObject',
        'class'      => Ollama::class,
        'url'        => $url,
        'httpClient' => [
            CallableWrapper::class,
            'createObject',
            'class'          => GuzzleHttpClient::class,
            'timeout'        => 90.0,
            'connectTimeout' => 10.0,
        ],
        'parameters' => [
            'options' => [
                'temperature'    => 0.3,
                'top_p'          => 0.9,
                'repeat_penalty' => 1.0,
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
            'Ты исследователь и технический аналитик.',
            'Собирай и структурируй факты по теме, обязательно отделяй факты от предположений.',
            'Если источников недостаточно — явно пиши, чего не хватает.',
            'Отвечай на русском, давай чёткую структуру: источники, факты, выводы, риски.',
        ],
        'steps' => [
            'Уточни формулировку запроса пользователя и цели исследования.',
            'Сформируй несколько уточнённых поисковых формулировок.',
            'Собери информацию из доступных источников (wiki, web) через инструменты поиска.',
            'Сгруппируй результаты по подтемам, отметь противоречия и пробелы.',
            'Сделай выводы и предложи дальнейшие шаги исследования.',
        ],
        'output' => [
            'Выведи структурированный отчёт: "Краткий ответ", "Детали", "Источники", "Риски и неопределённости".',
        ],
    ],

    'tools' => [
        // Универсальный поиск по Wikipedia (ru/en)
        [
            CallableWrapper::class,
            'createObject',
            'class'       => WikiSearchTool::class,
            'name'        => 'wiki_search',
            'description' => 'Поиск и загрузка статей из Wikipedia (ru/en).',
        ],
        // Специализированный поиск по российской RuWiki
        [
            CallableWrapper::class,
            'createObject',
            'class'       => RuWikiSearchTool::class,
            'name'        => 'ru_wiki_search',
            'description' => 'Поиск и загрузка статей из российской RuWiki.',
        ],
        // Универсальный поисковый инструмент (по умолчанию Wikipedia ru/en)
        [
            CallableWrapper::class,
            'createObject',
            'class'       => UniSearchTool::class,
            'name'        => 'uni_search',
            'description' => 'Общий инструмент поиска определений и справочной информации.',
        ],
        // Поиск web‑страниц через Ollama Web Search (если сконфигурирован API-ключ)
        /*
        [
            CallableWrapper::class,
            'createObject',
            'class'       => OllamaSearchTool::class,
            'ollamaApiKey'=> '', // при необходимости передать ключ
        ],
        */
    ],
];

