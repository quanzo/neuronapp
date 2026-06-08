<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\console;

/**
 * DTO снимка момента старта выполнения консольной LLM-команды.
 *
 * Захватывает unix-метку и монотонное время hrtime для последующего расчёта duration.
 *
 * Пример:
 *
 * <code>
 * $start = OutputExecutionTimingStartDto::captureNow();
 * $timing = OutputExecutionTimingDto::fromStartSnapshot($start);
 * </code>
 */
final class OutputExecutionTimingStartDto
{
    /**
     * @param UnixTimeDto $startedAt Метка старта (unixtime).
     * @param int|float $hrtimeNs  Значение hrtime(true) в момент старта.
     */
    public function __construct(
        private UnixTimeDto $startedAt,
        private int|float $hrtimeNs,
    ) {
    }

    /**
     * Фиксирует текущие метки времени старта команды.
     */
    public static function captureNow(): self
    {
        $hrtimeNs = hrtime(true);

        return new self(
            UnixTimeDto::now(),
            $hrtimeNs !== false ? $hrtimeNs : 0,
        );
    }

    /**
     * Unix-метка старта выполнения.
     */
    public function getStartedAt(): UnixTimeDto
    {
        return $this->startedAt;
    }

    /**
     * Монотонное время старта (наносекунды hrtime).
     */
    public function getHrtimeNs(): int|float
    {
        return $this->hrtimeNs;
    }
}
