<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\logger;

use Psr\Log\LoggerInterface;
use Psr\Log\InvalidArgumentException;
use Stringable;

/**
 * Пустая реализация логгера (PSR-3).
 *
 * Реализует LoggerInterface, но не записывает данные никуда:
 * все методы уровней и log() являются no-op.
 */
class NullLogger implements LoggerInterface
{
    /**
     * Система непригодна к использованию.
     *
     * @param array<string, mixed> $context
     */
    public function emergency(string|Stringable $message, array $context = []): void
    {
    }

    /**
     * Требуется немедленное действие.
     *
     * @param array<string, mixed> $context
     */
    public function alert(string|Stringable $message, array $context = []): void
    {
    }

    /**
     * Критические условия.
     *
     * @param array<string, mixed> $context
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
    }

    /**
     * Ошибки времени выполнения.
     *
     * @param array<string, mixed> $context
     */
    public function error(string|Stringable $message, array $context = []): void
    {
    }

    /**
     * Исключительные ситуации, не являющиеся ошибками.
     *
     * @param array<string, mixed> $context
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
    }

    /**
     * Нормальные, но значимые события.
     *
     * @param array<string, mixed> $context
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
    }

    /**
     * Интересные события.
     *
     * @param array<string, mixed> $context
     */
    public function info(string|Stringable $message, array $context = []): void
    {
    }

    /**
     * Детальная отладочная информация.
     *
     * @param array<string, mixed> $context
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
    }

    /**
     * Логирование с произвольным уровнем.
     *
     * @param mixed $level Уровень из Psr\Log\LogLevel
     * @param array<string, mixed> $context
     * @throws InvalidArgumentException Если уровень не распознан
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
    }
}
