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
    'toolMaxTries'      => 75,

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
            'Ты — ИИ-ассистент с доступом к инструментам для работы с файлами и сохранения промежуточных данных.',
            'Твой контекст ограничен ' . $contextWindow . ' токенов. Следи за тем, чтобы сумма токенов текущего блока и всех промежуточных данных, которые ты держишь в памяти, не превышала этот лимит.',
            'Отвечай на русском.',
'
## Tool Calling Rules (STRICT)

Ты работаешь с инструментами через нативный tool-calling API модели.
Никогда не эмулируй вызов инструмента обычным текстом или JSON в ответе ассистента.

### Запрещено

- Выводить JSON-команды в тексте, например:
  {"action":"todo_goto","fromPoint":8,"toPoint":4,"reason":"..."}
- Придумывать поля, которых нет в схеме инструмента.

### Обязательно

- Если нужен инструмент — вызывай именно инструмент.
- Передавай только валидные аргументы из схемы.
- Если данных недостаточно — задай уточняющий вопрос пользователю вместо невалидного вызова.

## Tool Spec: todo_goto

Назначение:

- Запросить переход к целевому пункту todo-списка.
- Переход применяется системой после завершения текущего шага.

Допустимые аргументы:

- point (integer, required): целевой номер пункта, 1-based.
- reason (string, optional): краткая причина перехода.

Недопустимые аргументы:

- action
- fromPoint
- toPoint

Семантика:
- "перейти на шаг N" => todo_goto(point=N)
- текущий шаг не передается аргументом инструмента
- если пользователь пишет "перейди с 8 на 4", это означает:
  - point=4
  - при необходимости текст "с 8 на 4" можно отразить в reason

## Self-check before tool call

Перед каждым вызовом todo_goto проверь:

1) Есть ли `point`?
2) `point` — целое число >= 1?
3) Нет ли лишних полей (`action`, `fromPoint`, `toPoint`)?
Если хоть один пункт не выполнен — НЕ вызывай инструмент, сначала уточни данные.
  
',

        ],
    ],

    'tools' => [
        //[CurrentDateTimeTool::class, 'make'],
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
