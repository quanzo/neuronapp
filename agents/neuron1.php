<?php

use app\modules\neuron\helpers\CallableWrapper;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\SystemPrompt;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;
use NeuronAI\MCP\McpConnector;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;

/**
 * Концепция агента
 * 
 * Агент - подключение к моедли LLM и ее настройка: системный промпт, опции, хранилище и т.п.
 */
return [
    'provider' => [
        CallableWrapper::class,
        'createObject',
        'class' => Ollama::class,
        'url'   => 'http://localhost:11434/api',
        // как передать опцию в модель vendor/neuron-core/neuron-ai/src/Providers/Ollama/HandleChat.php
        'parameters' => [
            'options' => [
                'temperature'    => 0.1,
                // (Nucleus Sampling): Контролирует разнообразие текста, определяя, из какого «пула» наиболее вероятных слов модель будет выбирать следующее слово
                'top_p'          => 0.9,
                // (Штраф за повторения): Подавляет повторение слов и фраз в сгенерированном тексте. Значение — число с плавающей запятой
                'repeat_penalty' => 1.1,
            ]
        ],

        'model' => 'slekrem/gpt-oss-claude-code-32k:20b',
    ],
    'instructions' => [
        CallableWrapper::class,
        'createObject',
        'class' => SystemPrompt::class,
        'background' => [
            "Твое имя Джозеф",
            "Ты специалист по аналитике данных. Выводы ты должен делать на основании четких и проверенных сведений. Если данных не хватает, или есть подозрения, что данные не корректные, то: составь список запросов на поиск данных; затем выполни поиск по списку запросов применяя предоставленные инструменты и источники; собери полученные результаты и из них выбери релевантные; на основании релевантных данных строй выводы и гипотезы.",
            'Никогда не спрашивай об необходимости поиска - сразу ищи всю доступную информацию, во всех доступных источника!',
            //''
            'Приоритетный язык общения: русский. Используй другой язык только по прямому указанию пользователя!',
            'Используй все доступные источники и инструменты для выполнения заданий и получения данных',
            'При получении информации от внешних источников или инструментов тебе следует выбрать только те данные, которые относятся к заданию или вопросу пользователя!',
            'После формирования ответа требуется выполнить дополнительную проверку на релевантность вопросу или просьбе пользователя. Если подготовленные данные не корректны, то следует их актуализировать!',
            'Тебе запрещено выдумывать несуществующие данные!',
            'Твоя задача использовать только надежную и проверенную информацию! Обращайся к инструментам для получения информации!',
        ],
        'steps' => [
            "Получи от пользователя задание на поиск и анализ информации.",
            'Сформируй аналитику на основаниий найденных данных'
        ],
        'output' => [
            "Выведи итоги",
        ],
    ],
    /*
    'mcp' => [
        [McpConnector::class, 'make',
            // если docker запущен под одним пользователем, а вебсервер под другим, то могут быть проблемы
            'config' => [
                "command" => "docker run -i --rm ddg_mcp_server",
                //"args" => ["run", "-i", "--rm", "ddg_mcp_server"],
                "type" => "stdio"
                // docker run -i --rm ddg_mcp_server
                // docker run -it --rm --name test_ddg ddg_mcp_server
            ]
        ],
    ],
    */
    'tools' => [
        [CalculatorToolkit::class, 'make'],
        //[CalendarToolkit::class, 'make']
        [CurrentDateTimeTool::class, 'make'],
        [FactorialTool::class, 'make'],
    ],

    'vectorStore' => [
        CallableWrapper::class,
        'createObject',
        QdrantVectorStore::class,
        'collectionUrl' => 'http://localhost:6333/collections/neuron2/',
        'key'           => null,
        'topK'          => 4,
        'dimension'     => 768,
    ],
    /* это векторное хранилище в файлах
    'vectorStore' => [
        CallableWrapper::class,
        'createObject',
        FileVectorStore::class,
        'name' => 'neuron1'
    ],
    */
    // конфигурация embedding модели для генерации векторов
    /**/'embeddingProvider' => [
        CallableWrapper::class,
        'createObject',
        OllamaEmbeddingsProvider::class,
        'model' => 'embeddinggemma:latest',
        'url'   => 'http://localhost:11434/api',
    ],
    'embeddingChunkSize' => 1500,
    //*/
];
