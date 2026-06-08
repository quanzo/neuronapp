<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\console\HrtimeDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see HrtimeDto} — монотонное время hrtime.
 */
final class HrtimeDtoTest extends TestCase
{
    /**
     * fromNanoseconds сохраняет переданное значение.
     */
    public function testFromNanosecondsStoresValue(): void
    {
        $dto = HrtimeDto::fromNanoseconds(1_500_000_000.0);

        $this->assertSame(1_500_000_000.0, $dto->getNanoseconds());
    }

    /**
     * now() возвращает неотрицательное значение hrtime.
     */
    public function testNowReturnsNonNegativeValue(): void
    {
        $dto = HrtimeDto::now();

        $this->assertGreaterThanOrEqual(0.0, $dto->getNanoseconds());
    }

    /**
     * toArray возвращает float наносекунд для JSON.
     */
    public function testToArrayReturnsNanoseconds(): void
    {
        $dto = HrtimeDto::fromNanoseconds(42.5);

        $this->assertSame(42.5, $dto->toArray());
    }

    /**
     * formatKeyValue формирует строку key=value.
     */
    public function testFormatKeyValue(): void
    {
        $dto = HrtimeDto::fromNanoseconds(1_000_000_000.0);

        $this->assertSame('startedAt=1000000000', $dto->formatKeyValue('startedAt'));
    }

    /**
     * add складывает наносекунды двух DTO.
     */
    public function testAdd(): void
    {
        $a = HrtimeDto::fromNanoseconds(1_000.0);
        $b = HrtimeDto::fromNanoseconds(2_500.0);

        $this->assertSame(3_500.0, $a->add($b)->getNanoseconds());
    }

    /**
     * subtract возвращает разницу в наносекундах.
     */
    public function testSubtract(): void
    {
        $end = HrtimeDto::fromNanoseconds(5_000_000_000.0);
        $start = HrtimeDto::fromNanoseconds(1_000_000_000.0);

        $this->assertSame(4_000_000_000.0, $end->subtract($start)->getNanoseconds());
    }

    /**
     * subtract при отрицательной дельте возвращает 0.
     */
    public function testSubtractClampsNegativeToZero(): void
    {
        $earlier = HrtimeDto::fromNanoseconds(1_000.0);
        $later = HrtimeDto::fromNanoseconds(9_000.0);

        $this->assertSame(0.0, $earlier->subtract($later)->getNanoseconds());
    }

    /**
     * toSeconds переводит наносекунды в секунды с округлением до 3 знаков.
     */
    public function testToSeconds(): void
    {
        $dto = HrtimeDto::fromNanoseconds(2_500_000_000.0);

        $this->assertSame(2.5, $dto->toSeconds());
    }

    /**
     * toSeconds округляет до трёх знаков после запятой.
     */
    public function testToSecondsRoundsToThreeDecimals(): void
    {
        $dto = HrtimeDto::fromNanoseconds(1_234_567.0);

        $this->assertSame(0.001, $dto->toSeconds());
    }

    /**
     * subtract нулевой дельты даёт toSeconds 0.0.
     */
    public function testSubtractZeroDeltaToSeconds(): void
    {
        $a = HrtimeDto::fromNanoseconds(100.0);
        $b = HrtimeDto::fromNanoseconds(100.0);

        $this->assertSame(0.0, $a->subtract($b)->toSeconds());
    }

    /**
     * add не мутирует исходные экземпляры.
     */
    public function testAddReturnsNewInstance(): void
    {
        $a = HrtimeDto::fromNanoseconds(1.0);
        $b = HrtimeDto::fromNanoseconds(2.0);
        $sum = $a->add($b);

        $this->assertNotSame($a, $sum);
        $this->assertSame(1.0, $a->getNanoseconds());
        $this->assertSame(2.0, $b->getNanoseconds());
    }

    /**
     * Два последовательных now() дают монотонно неубывающие значения.
     */
    public function testSequentialNowMonotonic(): void
    {
        $first = HrtimeDto::now();
        $second = HrtimeDto::now();

        $this->assertGreaterThanOrEqual($first->getNanoseconds(), $second->getNanoseconds());
    }
}
