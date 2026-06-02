<?php

/**
 * Агент суммаризации сессий `.mind`.
 *
 * Назначение:
 * - использоваться сервисом mind summary (`mind.session_summary.agent`);
 * - превращать транскрипт сессии в краткое, фактологичное резюме.
 *
 * База:
 * - конфигурация `testapp/agents/models/quick.php` (thinking=false поверх base.php).
 */

declare(strict_types=1);

use app\modules\neuron\helpers\CallableWrapper;
use NeuronAI\Agent\SystemPrompt;

$prompt = include __DIR__ . '/../prompts/system/mind-session-summarizer.php';
$ar     = include __DIR__ . '/models/quick.php';

$ar['instructions'] = [
    CallableWrapper::class,
    'createObject',
    'class'      => SystemPrompt::class,
    'background' => [
        'Твоё имя: Summarizer',
        $prompt,
    ],
];

// Для суммаризации инструменты не нужны (и могут ухудшить предсказуемость).
$ar['tools'] = [];
$ar['toolMaxTries'] = 0;

return $ar;
