<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\status;

use app\modules\neuron\interfaces\StatusInterface;

/**
 * Статус режима мыши (mouse reporting).
 *
 * Показывает, включён ли режим, который перехватывает клики (и может мешать выделению).
 *
 * Пример использования:
 *
 * ```php
 * $status = new MouseModeStatus(true);
 * ```
 */
final class MouseModeStatus implements StatusInterface
{
    public function __construct(
        private readonly bool $enabled,
    ) {
    }

    public function getText(): string
    {
        return $this->enabled ? 'MOUSE ON' : 'MOUSE OFF';
    }

    public function getColorCode(): string
    {
        return $this->enabled ? "\033[93m" : "\033[90m";
    }
}
