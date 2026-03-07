<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\logger;

use Psr\Log\LoggerInterface;
use Psr\Log\InvalidArgumentException;
use Stringable;

use function array_merge;

/**
 * Обёртка над PSR-3 логгером, добавляющая фиксированный контекст к каждому вызову.
 *
 * Используется для автоматической подстановки agent и session в логи
 * при исполнении через ConfigurationAgent.
 */
class ContextualLogger implements LoggerInterface
{
    /**
     * @param LoggerInterface $logger Внутренний логгер
     * @param array<string, mixed> $context Контекст, добавляемый к каждому сообщению
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $context = []
    ) {
    }

    /** @param array<string, mixed> $context */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->logger->emergency($message, array_merge($this->context, $context));
    }

    /** @param array<string, mixed> $context */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->logger->alert($message, array_merge($this->context, $context));
    }

    /** @param array<string, mixed> $context */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->logger->critical($message, array_merge($this->context, $context));
    }

    /** @param array<string, mixed> $context */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->logger->error($message, array_merge($this->context, $context));
    }

    /** @param array<string, mixed> $context */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->logger->warning($message, array_merge($this->context, $context));
    }

    /** @param array<string, mixed> $context */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->logger->notice($message, array_merge($this->context, $context));
    }

    /** @param array<string, mixed> $context */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->logger->info($message, array_merge($this->context, $context));
    }

    /** @param array<string, mixed> $context */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->logger->debug($message, array_merge($this->context, $context));
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     * @throws InvalidArgumentException
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->logger->log($level, $message, array_merge($this->context, $context));
    }
}
