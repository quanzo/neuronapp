<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\console\OutputExecutionTimingDto;
use app\modules\neuron\classes\dto\console\UnixTimeDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see OutputExecutionTimingDto} — метки времени выполнения LLM-команды.
 */
final class OutputExecutionTimingDtoTest extends TestCase
{
    /**
     * Нормальный расчёт duration из разницы hrtime в наносекундах.
     */
    public function testFromMeasurementCalculatesDuration(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(1_700_000_000),
            UnixTimeDto::fromSeconds(1_700_000_045),
            1_000_000_000,
            46_500_000_000,
        );

        $this->assertSame(1_700_000_000, $timing->getStartedAt()->getSeconds());
        $this->assertSame(1_700_000_045, $timing->getEndedAt()->getSeconds());
        $this->assertSame(45.5, $timing->getDurationSeconds());
    }

    /**
     * Нулевая дельта hrtime даёт duration 0.0.
     */
    public function testFromMeasurementZeroDelta(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(100),
            UnixTimeDto::fromSeconds(100),
            5_000,
            5_000,
        );

        $this->assertSame(0.0, $timing->getDurationSeconds());
    }

    /**
     * Малая длительность округляется до 3 знаков после запятой.
     */
    public function testFromMeasurementRoundsToThreeDecimals(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(1),
            UnixTimeDto::fromSeconds(1),
            0,
            1_234_567,
        );

        $this->assertSame(0.001, $timing->getDurationSeconds());
    }

    /**
     * Отрицательная дельта hrtime приводится к нулевой длительности.
     */
    public function testFromMeasurementNegativeDeltaClampedToZero(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(10),
            UnixTimeDto::fromSeconds(10),
            9_000,
            1_000,
        );

        $this->assertSame(0.0, $timing->getDurationSeconds());
    }

    /**
     * Длительность ровно 1 секунда.
     */
    public function testFromMeasurementOneSecond(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(100),
            UnixTimeDto::fromSeconds(101),
            0,
            1_000_000_000,
        );

        $this->assertSame(1.0, $timing->getDurationSeconds());
    }

    /**
     * Float-аргументы hrtime поддерживаются.
     */
    public function testFromMeasurementAcceptsFloatNanoseconds(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(1),
            UnixTimeDto::fromSeconds(2),
            1.5,
            2_500_000_001.5,
        );

        $this->assertSame(2.5, $timing->getDurationSeconds());
    }

    /**
     * toArray возвращает все три поля с корректными типами ключей.
     */
    public function testToArrayStructure(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(111),
            UnixTimeDto::fromSeconds(222),
            0,
            3_000_000_000,
        );
        $arr = $timing->toArray();

        $this->assertSame([
            'startedUnixTime' => 111,
            'endedUnixTime'   => 222,
            'durationSeconds' => 3.0,
        ], $arr);
    }

    /**
     * Геттеры возвращают UnixTimeDto с ожидаемыми секундами.
     */
    public function testGettersReturnUnixTimeDtoValues(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(42),
            UnixTimeDto::fromSeconds(43),
            100,
            200,
        );

        $this->assertSame(42, $timing->getStartedAt()->getSeconds());
        $this->assertSame(43, $timing->getEndedAt()->getSeconds());
        $this->assertSame(0.0, $timing->getDurationSeconds());
    }

    /**
     * Большая длительность корректно переводится из наносекунд в секунды.
     */
    public function testFromMeasurementLargeDuration(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(0),
            UnixTimeDto::fromSeconds(3600),
            0,
            3_600_000_000_000,
        );

        $this->assertSame(3600.0, $timing->getDurationSeconds());
    }

    /**
     * endedUnixTime может отличаться от started + duration (разные источники time/hrtime).
     */
    public function testUnixTimesIndependentFromHrtimeDuration(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(1_000),
            UnixTimeDto::fromSeconds(1_002),
            0,
            500_000_000,
        );

        $this->assertSame(1_000, $timing->getStartedAt()->getSeconds());
        $this->assertSame(1_002, $timing->getEndedAt()->getSeconds());
        $this->assertSame(0.5, $timing->getDurationSeconds());
        $this->assertNotSame(
            $timing->getEndedAt()->getSeconds() - $timing->getStartedAt()->getSeconds(),
            (int) $timing->getDurationSeconds()
        );
    }

    /**
     * Очень малая положительная дельта обнуляется при округлении до 3 знаков.
     */
    public function testFromMeasurementSubMillisecondDuration(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(1),
            UnixTimeDto::fromSeconds(1),
            0,
            999,
        );

        $this->assertSame(0.0, $timing->getDurationSeconds());
    }

    /**
     * Граничное значение: 0.0005 с округляется до 0.001 (round half up в PHP).
     */
    public function testFromMeasurementHalfMillisecondRounding(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(1),
            UnixTimeDto::fromSeconds(1),
            0,
            500_000,
        );

        $this->assertSame(0.001, $timing->getDurationSeconds());
    }

    /**
     * formatTextLines возвращает три строки key=value для md/txt.
     */
    public function testFormatTextLines(): void
    {
        $timing = OutputExecutionTimingDto::fromMeasurement(
            UnixTimeDto::fromSeconds(100),
            UnixTimeDto::fromSeconds(103),
            0,
            1_500_000_000,
        );

        $this->assertSame(
            [
                'startedUnixTime=100',
                'endedUnixTime=103',
                'durationSeconds=1.5',
            ],
            $timing->formatTextLines(),
        );
    }
}
