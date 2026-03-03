<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\exceptions\InvalidTypeException;
use app\modules\neuron\helpers\CallableWrapper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see CallableWrapper}.
 *
 * CallableWrapper расширяет стандартный тип callable: помимо обычных
 * callable-значений (замыкания, имена функций, массивы [класс, метод])
 * поддерживает «расширенные массивы» — [класс, метод, ...именованные параметры],
 * которые при вызове передают оставшиеся элементы массива как аргументы метода.
 *
 * Основные методы:
 *  - isCallable() — проверяет, является ли значение callable (включая расширенный формат);
 *  - call() — вызывает callable, передавая дополнительные параметры из массива;
 *  - createObject() — создаёт объект заданного класса с именованными аргументами.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\CallableWrapper}
 */
class CallableWrapperTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════
    //  isCallable — проверка вызываемости
    // ══════════════════════════════════════════════════════════════

    /**
     * Замыкание (Closure) является callable.
     */
    public function testIsCallableWithClosure(): void
    {
        $this->assertTrue(CallableWrapper::isCallable(fn() => true));
    }

    /**
     * Строковое имя встроенной функции (strlen) — callable.
     */
    public function testIsCallableWithFunctionName(): void
    {
        $this->assertTrue(CallableWrapper::isCallable('strlen'));
    }

    /**
     * Массив [класс, метод] — стандартный callable для статического вызова.
     */
    public function testIsCallableWithStaticMethodArray(): void
    {
        $this->assertTrue(CallableWrapper::isCallable([CallableWrapper::class, 'createObject']));
    }

    /**
     * Расширенный формат [класс, метод, ...аргументы] — распознаётся
     * CallableWrapper как callable.
     */
    public function testIsCallableWithExtendedArrayCallable(): void
    {
        $callable = [CallableWrapper::class, 'createObject', 'class' => \stdClass::class];
        $this->assertTrue(CallableWrapper::isCallable($callable));
    }

    /**
     * Строка, не соответствующая имени существующей функции, — не callable.
     */
    public function testIsCallableWithString(): void
    {
        $this->assertFalse(CallableWrapper::isCallable('nonExistentFunction12345'));
    }

    /**
     * Пустой массив — не callable (нужно минимум два элемента).
     */
    public function testIsCallableWithEmptyArray(): void
    {
        $this->assertFalse(CallableWrapper::isCallable([]));
    }

    /**
     * Массив с одним элементом — не callable (необходимы класс + метод).
     */
    public function testIsCallableWithSingleElementArray(): void
    {
        $this->assertFalse(CallableWrapper::isCallable(['single']));
    }

    /**
     * Целое число — не callable.
     */
    public function testIsCallableWithInteger(): void
    {
        $this->assertFalse(CallableWrapper::isCallable(42));
    }

    /**
     * null — не callable.
     */
    public function testIsCallableWithNull(): void
    {
        $this->assertFalse(CallableWrapper::isCallable(null));
    }

    // ══════════════════════════════════════════════════════════════
    //  call — вызов callable с аргументами
    // ══════════════════════════════════════════════════════════════

    /**
     * Вызов замыкания — получаем возвращённое значение.
     */
    public function testCallWithClosure(): void
    {
        $result = CallableWrapper::call(fn() => 'hello');
        $this->assertSame('hello', $result);
    }

    /**
     * Расширенный формат: [класс, метод, ...аргументы] — дополнительные
     * элементы массива передаются как аргументы метода.
     */
    public function testCallWithArrayCallable(): void
    {
        $result = CallableWrapper::call([CallableWrapper::class, 'createObject', 'class' => \stdClass::class]);
        $this->assertInstanceOf(\stdClass::class, $result);
    }

    /**
     * Обычный массив [класс, метод, позиционный_аргумент] — третий элемент
     * передаётся как первый аргумент вызываемого метода.
     */
    public function testCallWithSimpleArrayCallable(): void
    {
        $result = CallableWrapper::call([CallableWrapper::class, 'isCallable', fn() => true]);
        $this->assertTrue($result);
    }

    /**
     * Передача невалидного callable (массив из 4 элементов, не являющийся
     * callable) — выбрасывается InvalidTypeException.
     */
    public function testCallThrowsInvalidTypeExceptionForNonCallable(): void
    {
        $this->expectException(InvalidTypeException::class);
        CallableWrapper::call(['not', 'a', 'callable', 'array_with_extra']);
    }

    // ══════════════════════════════════════════════════════════════
    //  createObject — создание объекта по имени класса
    // ══════════════════════════════════════════════════════════════

    /**
     * Создание stdClass без аргументов конструктора.
     */
    public function testCreateObjectStdClass(): void
    {
        $obj = CallableWrapper::createObject(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $obj);
    }

    /**
     * Создание объекта с именованными параметрами конструктора.
     */
    public function testCreateObjectWithNamedParams(): void
    {
        $obj = CallableWrapper::createObject(
            \DateTimeImmutable::class,
            datetime: '2024-01-01'
        );
        $this->assertInstanceOf(\DateTimeImmutable::class, $obj);
        $this->assertSame('2024-01-01', $obj->format('Y-m-d'));
    }
}
