<?php

/**
 * Пример конфигурации агента "coder" — помощник по программированию.
 * Скопируйте в agents/coder.php для использования.
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\helpers\ShellToolFactory;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\VarExistTool;
use app\modules\neuron\tools\VarGetTool;
use app\modules\neuron\tools\VarListTool;
use app\modules\neuron\tools\VarPadTool;
use app\modules\neuron\tools\VarSetTool;
use app\modules\neuron\tools\VarUnsetTool;
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
                'think'          => false,
            ],
        ],
        'model' => 'qwen3.5:9b',
    ],

    'instructions' => [
        CallableWrapper::class,
        'createObject',
        'class'      => SystemPrompt::class,
        'background' => [
// Отвечай сразу, без каких-либо рассуждений, не используй теги <think> и не показывай свои мысли. Только готовый ответ.
<<<TEXT
Ты выполняешь задания и отвечаешь на вопросы. Основной язык общения - русский. Отвечай сразу, без каких-либо рассуждений, не используй теги <think> и не показывай свои мысли.

Твой контекст ограничен $contextWindow токенов. Следи за тем, чтобы сумма токенов текущего блока и всех промежуточных данных, которые ты держишь в памяти, не превышала этот лимит.

Сохраняй значения и промежуточные данные в переменных. Обязательно комментируй переменные. Если не хватает данных - поищи среди списка переменных. Читай и сохраняй переменные каждую отдельно!

## Tool Calling Rules (STRICT)

Ты работаешь с инструментами через нативный tool-calling API модели.

Никогда не эмулируй вызов инструмента обычным текстом или JSON в ответе ассистента.

### Запрещено

- Выводить JSON-команды в тексте, например:
  {"action":"todo_goto","fromPoint":8,"toPoint":4,"reason":"..."}
- Придумывать поля, которых нет в схеме инструмента.

### Обязательно

- Если нужен инструмент — вызывай именно инструмент.
- Передавай только валидные аргументы из схемы. Если в схеме есть __обязательные__ параметры, то они ОБЯЗАТЕЛЬНО должны быть указаны! 
- Если данных недостаточно — задай уточняющий вопрос пользователю вместо невалидного вызова.

TEXT
        ],
    ],

    'tools' => [
        //[CurrentDateTimeTool::class, 'make'],
        /*
        [ChatHistorySizeTool::class, 'make'],
        [ChatHistoryMetaTool::class, 'make'],
        [ChatHistoryMessageTool::class, 'make'],
        */

        /*[TodoGotoTool::class, 'make'],

        [GlobTool::class, 'make'],
        //[GrepTool::class, 'make'],
        

        [VarPadTool::class, 'make'],
        [VarListTool::class, 'make'],
        [VarExistTool::class, 'make'],
        [VarUnsetTool::class, 'make'],
        [VarGetTool::class, 'make'],
        [VarSetTool::class, 'make'],
        */
        [VarGetTool::class, 'make'],
        [VarSetTool::class, 'make'],
        [VarListTool::class, 'make'],
        [VarPadTool::class, 'make'],
        [ChunckViewTool::class, 'make'],
        [ChunckSizeTool::class, 'make'],

    ],
];
