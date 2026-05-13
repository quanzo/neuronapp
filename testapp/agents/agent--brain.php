<?php

/**
 * Основной агент
 */

use app\modules\neuron\helpers\CallableWrapper;
use app\modules\neuron\tools\VarGetTool;
use app\modules\neuron\tools\VarListTool;
use app\modules\neuron\tools\VarPadTool;
use app\modules\neuron\tools\VarSetTool;
use NeuronAI\Agent\SystemPrompt;

$ar     = include __DIR__ . '/models/base.php';
$prompt = include (__DIR__ . '/../prompts/system/base.php');

$ar['instructions'] = [
    CallableWrapper::class,
    'createObject',
    'class'      => SystemPrompt::class,
    'background' => [
        'Твоё имя: Брейн',
        $prompt
    ],
];
$ar['tools'] = [
    [VarGetTool::class, 'make'],
    [VarSetTool::class, 'make'],
    [VarListTool::class, 'make'],
    [VarPadTool::class, 'make'],
];
/*
$ar['skills'] = [
    'skill-file-block-summarize',
    'skill-text-finder'
];
*/

return $ar;
