<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\console\OutputExecutionTimingStartDto;
use app\modules\neuron\classes\dto\console\OutputExecutionTimingDto;
use app\modules\neuron\classes\dto\console\UnixTimeDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see OutputExecutionTimingStartDto} — снимок старта выполнения команды.
 */
final class OutputExecutionTimingStartDtoTest extends TestCase
{
    /**
     * captureNow() возвращает непустую метку startedAt.
     */
    public function testCaptureNowReturnsStartedAt(): void
    {
        $start = OutputExecutionTimingStartDto::captureNow();

        $this->assertInstanceOf(UnixTimeDto::class, $start->getStartedAt());
        $this->assertGreaterThan(0, $start->getStartedAt()->getSeconds());
    }

    /**
     * captureNow() фиксирует положительное значение hrtime.
     */
    public function testCaptureNowReturnsPositiveHrtime(): void
    {
        $start = OutputExecutionTimingStartDto::captureNow();

        $this->assertGreaterThan(0, $start->getHrtimeNs());
    }

    /**
     * Конструктор сохраняет переданные значения.
     */
    public function testConstructorStoresValues(): void
    {
        $unix = UnixTimeDto::fromSeconds(100);
        $start = new OutputExecutionTimingStartDto($unix, 9_000_000_000);

        $this->assertSame($unix, $start->getStartedAt());
        $this->assertSame(9_000_000_000, $start->getHrtimeNs());
    }

    /**
     * fromStartSnapshot на основе captureNow даёт неотрицательную длительность.
     */
    public function testFromStartSnapshotProducesNonNegativeDuration(): void
    {
        $start = OutputExecutionTimingStartDto::captureNow();
        $timing = OutputExecutionTimingDto::fromStartSnapshot($start);

        $this->assertGreaterThanOrEqual(0.0, $timing->getDurationSeconds());
    }

    /**
     * fromStartSnapshot сохраняет startedAt из снимка.
     */
    public function testFromStartSnapshotPreservesStartedAt(): void
    {
        $unix = UnixTimeDto::fromSeconds(1_234_567);
        $start = new OutputExecutionTimingStartDto($unix, 1_000);
        $timing = OutputExecutionTimingDto::fromStartSnapshot($start);

        $this->assertSame(1_234_567, $timing->getStartedAt()->getSeconds());
    }

    /**
     * endedAt в fromStartSnapshot не меньше startedAt по unix (в типичном случае).
     */
    public function testFromStartSnapshotEndedAtNotBeforeStartedAt(): void
    {
        $start = OutputExecutionTimingStartDto::captureNow();
        $timing = OutputExecutionTimingDto::fromStartSnapshot($start);

        $this->assertGreaterThanOrEqual(
            $timing->getStartedAt()->getSeconds(),
            $timing->getEndedAt()->getSeconds()
        );
    }

    /**
     * hrtime может быть float — конструктор принимает float.
     */
    public function testConstructorAcceptsFloatHrtime(): void
    {
        $start = new OutputExecutionTimingStartDto(UnixTimeDto::fromSeconds(1), 1.5);

        $this->assertSame(1.5, $start->getHrtimeNs());
    }

    /**
     * captureNow при недоступном hrtime использует fallback 0 (ветка false).
     */
    public function testCaptureNowHandlesHrtimeFalse(): void
    {
        if (function_exists('hrtime')) {
            $this->markTestSkipped('hrtime всегда доступен в этой среде');
        }

        $start = OutputExecutionTimingStartDto::captureNow();

        $this->assertSame(0, $start->getHrtimeNs());
    }

    /**
     * Два последовательных captureNow дают монотонно неубывающий hrtime.
     */
    public function testSequentialCaptureNowHrtimeMonotonic(): void
    {
        $first = OutputExecutionTimingStartDto::captureNow();
        $second = OutputExecutionTimingStartDto::captureNow();

        $this->assertGreaterThanOrEqual($first->getHrtimeNs(), $second->getHrtimeNs());
    }

    /**
     * getStartedAt возвращает тот же экземпляр UnixTimeDto.
     */
    public function testGetStartedAtReturnsSameInstance(): void
    {
        $unix = UnixTimeDto::fromSeconds(50);
        $start = new OutputExecutionTimingStartDto($unix, 100);

        $this->assertSame($unix, $start->getStartedAt());
    }
}
