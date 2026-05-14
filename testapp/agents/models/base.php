<?php

/**
 * Базовая модель: думает
 */

use app\modules\neuron\helpers\CallableWrapper;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\OpenAILike;

$homeDir = getenv('HOME');

if ($homeDir === false || $homeDir === '') {
    $homeDir = $_SERVER['HOME'] ?? '';
}

$url           = 'http://localhost:11521/v1';
$model         = 'base';
$contextWindow = 131072;
$key           = 'sk-qwertyuiop';
//$key           = 'sk-qwertyuiop33333333';

$ar = [
    'enableChatHistory' => true,
    'contextWindow'     => $contextWindow,
    'toolMaxTries'      => 75,
    'llmPayloadLogMode' => 'summary',

    'provider' => [
        CallableWrapper::class,
        'createObject',
        /**/
        'class'      => OpenAILike::class,
        'key'        => $key,
        'baseUri'    => $url,
        'httpClient' => [
            CallableWrapper::class,
            'createObject',
            'class'          => GuzzleHttpClient::class,
            'timeout'        => 75.0,
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
        'model' => $model,
    ],
];
return $ar;
