<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\enums\VarDataTypeEnum;
use app\modules\neuron\helpers\VarMergeHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see VarMergeHelper}.
 */
final class VarMergeHelperTest extends TestCase
{
    public function testStringPadAddsNewlineWhenNeeded(): void
    {
        // Граница: существующая строка без завершающего \n и добавка без начального \n.
        $r = VarMergeHelper::mergeForPad('first', 'second');
        $this->assertTrue($r->success);
        $this->assertSame("first\nsecond", $r->merged);
        $this->assertSame('string', $r->mergedType);
    }

    public function testStringPadDoesNotDuplicateNewlines(): void
    {
        // Граница: existing заканчивается \n, append начинается \n — должен быть ровно один перевод строки.
        $r = VarMergeHelper::mergeForPad("first\n", "\nsecond");
        $this->assertTrue($r->success);
        $this->assertSame("first\nsecond", $r->merged);
    }

    public function testStringPadRejectsNonStringAppend(): void
    {
        // Негатив: попытка дополнить строку нестроковыми данными.
        $r = VarMergeHelper::mergeForPad('log', ['a' => 1]);
        $this->assertFalse($r->success);
        $this->assertSame(VarDataTypeEnum::STRING, $r->existingType);
        $this->assertSame(VarDataTypeEnum::ARRAY, $r->appendType);
    }

    public function testListArrayAppendsListArray(): void
    {
        // Нормально: list + list → конкатенация.
        $r = VarMergeHelper::mergeForPad([1, 2], [3, 4]);
        $this->assertTrue($r->success);
        $this->assertSame([1, 2, 3, 4], $r->merged);
        $this->assertSame('array', $r->mergedType);
    }

    public function testListArrayAppendsScalarAsItem(): void
    {
        // Граница: list + scalar → добавить один элемент.
        $r = VarMergeHelper::mergeForPad([1, 2], 3);
        $this->assertTrue($r->success);
        $this->assertSame([1, 2, 3], $r->merged);
    }

    public function testListArrayAppendsMapAsSingleItem(): void
    {
        // Граница: list + map-array → добавить map как один элемент (не разворачивать по ключам).
        $r = VarMergeHelper::mergeForPad([1], ['a' => 2]);
        $this->assertTrue($r->success);
        $this->assertSame([1, ['a' => 2]], $r->merged);
    }

    public function testMapArrayMergesWithOverwriteOnConflicts(): void
    {
        // Нормально: map + map → merge по ключам, конфликт перезаписывается.
        $r = VarMergeHelper::mergeForPad(['a' => 1, 'b' => 1], ['b' => 2, 'c' => 3]);
        $this->assertTrue($r->success);
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $r->merged);
    }

    public function testMapArrayRejectsListAppend(): void
    {
        // Негатив: map + list → ошибка (list не имеет ключей для корректного merge).
        $r = VarMergeHelper::mergeForPad(['a' => 1], [1, 2, 3]);
        $this->assertFalse($r->success);
        $this->assertSame(VarDataTypeEnum::ARRAY, $r->existingType);
        $this->assertSame(VarDataTypeEnum::ARRAY, $r->appendType);
    }

    public function testNumberPadAddsNumbers(): void
    {
        // Нормально: number + number → арифметическое сложение.
        $r = VarMergeHelper::mergeForPad(10, 5);
        $this->assertTrue($r->success);
        $this->assertSame(15, $r->merged);
        $this->assertSame('number', $r->mergedType);
    }

    public function testNumberPadRejectsNonNumberAppend(): void
    {
        // Негатив: number + string → ошибка.
        $r = VarMergeHelper::mergeForPad(10, '5');
        $this->assertFalse($r->success);
        $this->assertSame(VarDataTypeEnum::NUMBER, $r->existingType);
        $this->assertSame(VarDataTypeEnum::STRING, $r->appendType);
    }

    public function testNullExistingActsAsEmpty(): void
    {
        // Граница: existing=null → результат становится append (любой тип).
        $r = VarMergeHelper::mergeForPad(null, ['a' => 1]);
        $this->assertTrue($r->success);
        $this->assertSame(['a' => 1], $r->merged);
        $this->assertSame('array', $r->mergedType);
    }

    public function testBooleanExistingIsNotSupported(): void
    {
        // Негатив: boolean не поддерживается для pad.
        $r = VarMergeHelper::mergeForPad(true, false);
        $this->assertFalse($r->success);
        $this->assertSame(VarDataTypeEnum::BOOLEAN, $r->existingType);
    }
}
