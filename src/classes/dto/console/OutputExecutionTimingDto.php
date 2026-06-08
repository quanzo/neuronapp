<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\console;

/**
 * DTO меток времени выполнения консольной LLM-команды.
 *
 * Старт и окончание — {@see UnixTimeDto}; длительность — монотонный замер hrtime.
 *
 * Пример:
 *
 * <code>
 * $start = OutputExecutionTimingStartDto::captureNow();
 * $timing = OutputExecutionTimingDto::fromStartSnapshot($start);
 * </code>
 */
final class OutputExecutionTimingDto
{
    /**
     * @param UnixTimeDto $startedAt        Метка старта.
     * @param UnixTimeDto $endedAt          Метка окончания.
     * @param float       $durationSeconds  Длительность в секундах (3 знака после запятой).
     */
    public function __construct(
        private UnixTimeDto $startedAt,
        private UnixTimeDto $endedAt,
        private float $durationSeconds,
    ) {
    }

    /**
     * Метка старта выполнения команды.
     */
    public function getStartedAt(): UnixTimeDto
    {
        return $this->startedAt;
    }

    /**
     * Метка окончания выполнения команды.
     */
    public function getEndedAt(): UnixTimeDto
    {
        return $this->endedAt;
    }

    /**
     * Длительность выполнения в секундах (монотонный замер hrtime).
     */
    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    /**
     * Создаёт DTO по снимку старта и текущему времени окончания.
     */
    public static function fromStartSnapshot(OutputExecutionTimingStartDto $start): self
    {
        $endedAt = UnixTimeDto::now();
        $endNs = hrtime(true);

        return self::fromMeasurement(
            $start->getStartedAt(),
            $endedAt,
            $start->getHrtimeNs(),
            $endNs !== false ? $endNs : $start->getHrtimeNs(),
        );
    }

    /**
     * Создаёт DTO из меток unixtime и монотонных наносекунд hrtime.
     *
     * @param UnixTimeDto $startedAt Метка старта.
     * @param UnixTimeDto $endedAt   Метка окончания.
     * @param int|float   $startNs   Значение hrtime(true) в начале.
     * @param int|float   $endNs     Значение hrtime(true) в конце.
     */
    public static function fromMeasurement(
        UnixTimeDto $startedAt,
        UnixTimeDto $endedAt,
        int|float $startNs,
        int|float $endNs,
    ): self {
        return new self(
            $startedAt,
            $endedAt,
            self::calculateDurationSeconds($startNs, $endNs),
        );
    }

    /**
     * Строки timing для md/txt вывода.
     *
     * @return list<string>
     */
    public function formatTextLines(): array
    {
        return [
            $this->startedAt->formatKeyValue('startedUnixTime'),
            $this->endedAt->formatKeyValue('endedUnixTime'),
            'durationSeconds=' . $this->durationSeconds,
        ];
    }

    /**
     * Сериализует метки времени для JSON.
     *
     * @return array{startedUnixTime: int, endedUnixTime: int, durationSeconds: float}
     */
    public function toArray(): array
    {
        return [
            'startedUnixTime' => $this->startedAt->toArray(),
            'endedUnixTime'   => $this->endedAt->toArray(),
            'durationSeconds' => $this->durationSeconds,
        ];
    }

    /**
     * Вычисляет длительность в секундах из разницы hrtime (наносекунды).
     *
     * @param int|float $startNs Значение hrtime(true) в начале.
     * @param int|float $endNs   Значение hrtime(true) в конце.
     */
    private static function calculateDurationSeconds(int|float $startNs, int|float $endNs): float
    {
        $deltaNs = (float) $endNs - (float) $startNs;
        if ($deltaNs < 0) {
            $deltaNs = 0.0;
        }

        return round($deltaNs / 1_000_000_000.0, 3);
    }
}
