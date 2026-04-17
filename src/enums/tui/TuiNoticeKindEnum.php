<?php

declare(strict_types=1);

namespace app\modules\neuron\enums\tui;

/**
 * Enum видов уведомлений для TUI.
 *
 * Пример использования:
 *
 * ```php
 * $kind = TuiNoticeKindEnum::Error;
 * ```
 */
enum TuiNoticeKindEnum: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
}
