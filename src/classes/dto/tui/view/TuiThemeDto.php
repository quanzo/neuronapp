<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\view;

/**
 * DTO темы TUI (ANSI палитра).
 *
 * Важно: хранит сырые escape-коды и не пытается «угадать» поддержку цветов терминалом.
 *
 * Пример использования:
 *
 * ```php
 * $theme = new TuiThemeDto();
 * echo $theme->accent() . 'OK' . $theme->reset();
 * ```
 */
final class TuiThemeDto
{
    public function __construct(
        private readonly string $muted = "\033[90m",
        private readonly string $accent = "\033[92m",
        private readonly string $error = "\033[91m",
        private readonly string $warning = "\033[93m",
        private readonly string $success = "\033[92m",
        private readonly string $code = "\033[38;5;245m",
        private readonly string $reset = "\033[0m",
    ) {
    }

    public function muted(): string
    {
        return $this->muted;
    }

    public function accent(): string
    {
        return $this->accent;
    }

    public function error(): string
    {
        return $this->error;
    }

    public function warning(): string
    {
        return $this->warning;
    }

    public function success(): string
    {
        return $this->success;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function reset(): string
    {
        return $this->reset;
    }
}
