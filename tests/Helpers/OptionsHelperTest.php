<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\OptionsHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see OptionsHelper}.
 *
 * OptionsHelper — статический хелпер для преобразования значений опций.
 * toBool() приводит значение опции к boolean (1/'true' → true, остальное → false).
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\OptionsHelper}
 */
class OptionsHelperTest extends TestCase
{
    /**
     * null — false.
     */
    public function testToBoolNullReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(null));
    }

    /**
     * 0 — false.
     */
    public function testToBoolZeroReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(0));
    }

    /**
     * false — false.
     */
    public function testToBoolFalseReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(false));
    }

    /**
     * Строка 'false' — false.
     */
    public function testToBoolStringFalseReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool('false'));
    }

    /**
     * 1 — true.
     */
    public function testToBoolOneReturnsTrue(): void
    {
        $this->assertTrue(OptionsHelper::toBool(1));
    }

    /**
     * true — true.
     */
    public function testToBoolTrueReturnsTrue(): void
    {
        $this->assertTrue(OptionsHelper::toBool(true));
    }

    /**
     * Строка 'true' — true.
     */
    public function testToBoolStringTrueReturnsTrue(): void
    {
        $this->assertTrue(OptionsHelper::toBool('true'));
    }

    /**
     * Неожиданное строковое значение ('yes') — false.
     */
    public function testToBoolUnexpectedStringReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool('yes'));
    }

    /**
     * Пустая строка — false.
     */
    public function testToBoolEmptyStringReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(''));
    }

    /**
     * Массив — false.
     */
    public function testToBoolArrayReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool([]));
    }

    /**
     * Число, отличное от 0 и 1 (например 42) — false.
     */
    public function testToBoolOtherNumberReturnsFalse(): void
    {
        $this->assertFalse(OptionsHelper::toBool(42));
    }
}
