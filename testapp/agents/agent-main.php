<?php

/**
 * Пример конфигурации агента
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\VarGetTool;
use app\modules\neuron\tools\VarListTool;
use app\modules\neuron\tools\VarPadTool;
use app\modules\neuron\tools\VarSetTool;
use app\modules\neuron\tools\ViewTool;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\GuzzleHttpClient;

$homeDir = getenv('HOME');

if ($homeDir === false || $homeDir === '') {
    $homeDir = $_SERVER['HOME'] ?? '';
}

$url           = 'http://localhost:11434/api';
$contextWindow = 8192 * 4 * 2 * 2;
$prompt        = include (__DIR__ . '/../prompts/system/base.php');

$ar = [
    'enableChatHistory' => true,
    'contextWindow'     => $contextWindow,
    'toolMaxTries'      => 75,
    'llmPayloadLogMode' => 'summary',

    'provider' => [
        CallableWrapper::class,
        'createObject',
        'class'      => Ollama::class,
        'url'        => $url,
        'httpClient' => [
            CallableWrapper::class,
            'createObject',
            'class'          => GuzzleHttpClient::class,
            'timeout'        => 150.0,
            'connectTimeout' => 10.0,
        ],
        'parameters' => [
            'options' => [
                // Управляет «креативностью». Чем выше значение, тем более случайными и разнообразными будут ответы. Низкие значения делают модель более сфокусированной и детерминированной
                'temperature'    => 0.3,

                // Также известен как Nucleus Sampling. Ограничивает выбор токенов теми, чья совокупная вероятность не превышает top_p. Высокое значение (например, 0.95) даёт большее разнообразие, низкое (0.5) — более консервативные ответы
                'top_p'          => 0.95,

                // Ограничивает выбор токенов k наиболее вероятными. Высокое значение увеличивает разнообразие, низкое делает ответы более «безопасными» и предсказуемыми
                'top_k' => 40,
                
                // Штрафует модель за повторение уже сказанных слов. Значение больше 1.0 снижает вероятность повторов, а меньше 1.0, наоборот, поощряет их
                'repeat_penalty' => 1.1,

                // Штрафует модель за использование любых токенов, которые уже встречались в тексте. Поощряет модель говорить о новых темах
                'presence_penalty' => 0,

                // Штрафует модель за использование токенов пропорционально тому, как часто они уже встречались. Помогает бороться с повторениями
                'frequency_penalty' => 0,

                // Устанавливает начальное число для генератора случайных чисел. Использование одного и того же seed с тем же запросом гарантирует идентичный ответ, что полезно для отладки
                // 'seed' => 0,

                // То же самое, что и max_tokens — максимальное число токенов для генерации
                //'num_predict' => -1,

                // Массив строк, при появлении которых генерация немедленно остановится.
                //'stop' => [],

                // Размер контекстного окна — количество токенов, которые модель «помнит» из предыдущего диалога
                'num_ctx'        => $contextWindow,
                //'think'          => false,
                'stream'         => false,
            ],
        ],
        'model' => 'qwen3.5:9b',
        //'model' => 'qwen3.5:cloud',
        //'model' => 'gemma4:e4b',
    ],

    'instructions' => [
        CallableWrapper::class,
        'createObject',
        'class'      => SystemPrompt::class,
        'background' => [
            $prompt
        ],
    ],

    'tools' => [
        [VarGetTool::class, 'make'],
        [VarSetTool::class, 'make'],
        [VarListTool::class, 'make'],
        [VarPadTool::class, 'make'],
    ],

    'skills' => [
        'skill-file-block-summarize',
        'skill-text-finder'
    ],
];
return $ar;
