<?php

/**
 * Пример конфигурации агента "coder" — помощник по программированию.
 * Скопируйте в agents/coder.php для использования.
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\helpers\ShellToolFactory;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\IntermediateDeleteTool;
use app\modules\neuron\tools\IntermediateExistTool;
use app\modules\neuron\tools\IntermediateListTool;
use app\modules\neuron\tools\IntermediateLoadTool;
use app\modules\neuron\tools\IntermediatePadTool;
use app\modules\neuron\tools\ViewTool;
use app\modules\neuron\tools\ChatHistoryMessageTool;
use app\modules\neuron\tools\ChatHistoryMetaTool;
use app\modules\neuron\tools\ChatHistorySizeTool;
use app\modules\neuron\tools\ChunckSizeTool;
use app\modules\neuron\tools\ChunckViewTool;
use app\modules\neuron\tools\TodoGotoTool;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;

$homeDir = getenv('HOME');

if ($homeDir === false || $homeDir === '') {
    $homeDir = $_SERVER['HOME'] ?? '';
}

$contextWindow = 8192 * 4;

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
            'timeout'        => 1800.0,
            'connectTimeout' => 10.0,
        ],
        'parameters' => [
            'options' => [
                'temperature'    => 0.2,
                'top_p'          => 0.95,
                'repeat_penalty' => 1.1,
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
            'Ты — ИИ-ассистент с доступом к инструментам для работы с файлами и сохранения промежуточных данных. Твоя задача — прочитать большой текстовый файл (размер превышает твой контекст в 32k токенов) и составить краткое резюме его содержимого. Файл нельзя прочитать целиком из-за ограничений, поэтому используй предоставленные инструменты для обработки по частям.',
            'Твой контекст ограничен 32k токенов. Следи за тем, чтобы сумма токенов текущего блока и всех промежуточных данных, которые ты держишь в памяти, не превышала этот лимит.',
            'Файл большой, поэтому читай его блоками разумного размера (например, по 10k–20k токенов), чтобы оставалось место для обработки.',
            'Отвечай на русском.',
            
            'Храни в активной памяти только текущий блок и необходимые промежуточные резюме. Остальное сохраняй и загружай по мере надобности.',
            'При сохранении резюме частей старайся, чтобы они были краткими (не более нескольких сотен токенов), чтобы итоговое объединение не переполнило контекст.',
            'Если файл содержит структурированные данные (например, главы, разделы), учитывай это при разбиении.',

            'Используй размер чанка в 100 строк. Максимальный размер чанка в символах = 10000.',
            'Для сохранения резюме и промежуточных данных используем `intermediate_pad`, а для чтения - `intermediate_pad`. Список получаем через `intermediate_list`.',

            'Файл по чанкам читаем через `view_chunk`.'

        ],
        /*'steps' => [
            'Определи путь к файлу (скорее всего, он известен). Если нет — уточни у пользователя.',
            'Определи количество строк в файле',
            'Начни читать и обрабатывать файл по частям. Начни с start_line = 0. Используй размер чанка в 100 строк. Максимальный размер чанка в символах = 10000',
            'Читай блок файла с помощью `view_chunk`.',
            'Для каждого блока составь краткое резюме этого фрагмента (summary). Старайся выделить ключевые идеи, факты, темы.',
            'Сохрани полученное резюме под уникальным ключом, например, "part_0", "part_1" и т.д., используя `intermediate_pad`.',
            'Увеличь start_line на размер прочитанного блока и продолжай, пока не будет достигнут конец файла (когда прочитано меньше запрошенного размера или блок пуст).',
            'После обработки всех частей у тебя будет набор сохранённых резюме.',
            'Теперь нужно объединить их в общее резюме. Но если количество частей велико и их резюме в сумме не помещаются в контекст, примени иерархический подход: сгруппируй несколько резюме частей, объедини их в одно промежуточное резюме более высокого уровня, сохрани его под новым ключом; повторяй, пока не получишь одно итоговое резюме, которое помещается в контекст.',
            'Для чтения сохранённых данных используй `intermediate_load`.',

        ],*/
        //'output' => ['Выведи итогb работы'],
    ],

    'tools' => [
        [CurrentDateTimeTool::class, 'make'],
        /*
        [ChatHistorySizeTool::class, 'make'],
        [ChatHistoryMetaTool::class, 'make'],
        [ChatHistoryMessageTool::class, 'make'],
        */

        [TodoGotoTool::class, 'make'],

        [GlobTool::class, 'make'],
        [GrepTool::class, 'make'],
        [ChunckViewTool::class, 'make'],
        [ChunckSizeTool::class, 'make'],

        [IntermediatePadTool::class, 'make'],
        [IntermediateListTool::class, 'make'],
        [IntermediateExistTool::class, 'make'],
        [IntermediateDeleteTool::class, 'make'],
        [IntermediateLoadTool::class, 'make'],

    ],
];
