<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\console\UnixTimeDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see UnixTimeDto} — value-object unix timestamp.
 */
final class UnixTimeDtoTest extends TestCase
{
    /**
     * fromSeconds сохраняет переданное значение.
     */
    public function testFromSecondsStoresValue(): void
    {
        $dto = UnixTimeDto::fromSeconds(1_700_000_000);

        $this->assertSame(1_700_000_000, $dto->getSeconds());
    }

    /**
     * now() возвращает значение близкое к time().
     */
    public function testNowReturnsCurrentTime(): void
    {
        $before = time();
        $dto = UnixTimeDto::now();
        $after = time();

        $this->assertGreaterThanOrEqual($before, $dto->getSeconds());
        $this->assertLessThanOrEqual($after, $dto->getSeconds());
    }

    /**
     * toArray возвращает плоский int для JSON.
     */
    public function testToArrayReturnsSeconds(): void
    {
        $dto = UnixTimeDto::fromSeconds(42);

        $this->assertSame(42, $dto->toArray());
    }

    /**
     * formatKeyValue формирует строку key=value.
     */
    public function testFormatKeyValue(): void
    {
        $dto = UnixTimeDto::fromSeconds(1717843200);

        $this->assertSame('startedUnixTime=1717843200', $dto->formatKeyValue('startedUnixTime'));
    }

    /**
     * formatKeyValue с другим ключом.
     */
    public function testFormatKeyValueWithCustomKey(): void
    {
        $dto = UnixTimeDto::fromSeconds(99);

        $this->assertSame('endedUnixTime=99', $dto->formatKeyValue('endedUnixTime'));
    }

    /**
     * Граничное значение 0 допустимо.
     */
    public function testFromSecondsZero(): void
    {
        $dto = UnixTimeDto::fromSeconds(0);

        $this->assertSame(0, $dto->getSeconds());
        $this->assertSame('startedUnixTime=0', $dto->formatKeyValue('startedUnixTime'));
    }

    /**
     * Большое значение unix timestamp сохраняется.
     */
    public function testFromSecondsLargeValue(): void
    {
        $dto = UnixTimeDto::fromSeconds(2_147_483_647);

        $this->assertSame(2_147_483_647, $dto->getSeconds());
    }

    /**
     * Отрицательное значение допускается (исторические даты до 1970).
     */
    public function testFromSecondsNegativeValue(): void
    {
        $dto = UnixTimeDto::fromSeconds(-1);

        $this->assertSame(-1, $dto->getSeconds());
    }

    /**
     * Два экземпляра fromSeconds с одинаковым значением равны по getSeconds.
     */
    public function testFromSecondsEqualityByValue(): void
    {
        $a = UnixTimeDto::fromSeconds(123);
        $b = UnixTimeDto::fromSeconds(123);

        $this->assertSame($a->getSeconds(), $b->getSeconds());
    }

    /**
     * now() и fromSeconds(time()) дают согласованные секунды в узком окне.
     */
    public function testNowConsistentWithTime(): void
    {
        $fixed = time();
        $fromFixed = UnixTimeDto::fromSeconds($fixed);
        $now = UnixTimeDto::now();

        $this->assertLessThanOrEqual(1, abs($now->getSeconds() - $fromFixed->getSeconds()));
    }

    /**
     * toArray и getSeconds возвращают одно и то же.
     */
    public function testToArrayMatchesGetSeconds(): void
    {
        $dto = UnixTimeDto::fromSeconds(555);

        $this->assertSame($dto->getSeconds(), $dto->toArray());
    }

    /**
     * formatKeyValue с пустым ключом (некорректный, но не падает).
     */
    public function testFormatKeyValueEmptyKey(): void
    {
        $dto = UnixTimeDto::fromSeconds(7);

        $this->assertSame('=7', $dto->formatKeyValue(''));
    }
}
