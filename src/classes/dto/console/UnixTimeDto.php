<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\console;

/**
 * DTO unix timestamp в секундах.
 *
 * Пример:
 *
 * <code>
 * $started = UnixTimeDto::now();
 * $line = $started->formatKeyValue('startedUnixTime');
 * </code>
 */
final class UnixTimeDto
{
    /**
     * @param int $seconds Unix timestamp в секундах.
     */
    public function __construct(
        private int $seconds,
    ) {
    }

    /**
     * Текущее время системы.
     */
    public static function now(): self
    {
        return new self(time());
    }

    /**
     * Явное значение unix timestamp (для тестов и десериализации).
     *
     * @param int $seconds Unix timestamp в секундах.
     */
    public static function fromSeconds(int $seconds): self
    {
        return new self($seconds);
    }

    /**
     * Unix timestamp в секундах.
     */
    public function getSeconds(): int
    {
        return $this->seconds;
    }

    /**
     * Сериализует метку для JSON (плоский int).
     */
    public function toArray(): int
    {
        return $this->seconds;
    }

    /**
     * Форматирует метку для md/txt вывода.
     *
     * @param string $key Имя поля (например, startedUnixTime).
     */
    public function formatKeyValue(string $key): string
    {
        return $key . '=' . $this->seconds;
    }
}
