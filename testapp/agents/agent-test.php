<?php

/**
 * Переводчик текста
 */

use app\modules\neuron\helpers\CallableWrapper;
use NeuronAI\Agent\SystemPrompt;


$prompt = include (__DIR__ . '/../prompts/system/base.php');
$ar     = include __DIR__ . '/models/quick.php';

$ar['skills'] = [
    'skill-file-block-summarize',
];

return $ar;
