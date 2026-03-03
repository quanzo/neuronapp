<?php

declare(strict_types=1);

namespace Tests\Exceptions;

use app\modules\neuron\exceptions\InvalidTypeException;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see InvalidTypeException}.
 *
 * InvalidTypeException — пользовательское исключение, выбрасываемое
 * при передаче аргумента неверного типа (например, в CallableWrapper::call()).
 * Наследует стандартный \Exception.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\exceptions\InvalidTypeException}
 */
class InvalidTypeExceptionTest extends TestCase
{
    /**
     * Класс наследует стандартный \Exception.
     */
    public function testExtendsException(): void
    {
        $ex = new InvalidTypeException('test');
        $this->assertInstanceOf(\Exception::class, $ex);
    }

    /**
     * Сообщение исключения соответствует переданному в конструктор.
     */
    public function testMessage(): void
    {
        $ex = new InvalidTypeException('Custom message');
        $this->assertSame('Custom message', $ex->getMessage());
    }

    /**
     * Код исключения задаётся вторым аргументом конструктора.
     */
    public function testCode(): void
    {
        $ex = new InvalidTypeException('msg', 42);
        $this->assertSame(42, $ex->getCode());
    }

    /**
     * Исключение можно выбросить и перехватить стандартным механизмом.
     */
    public function testThrowable(): void
    {
        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage('boom');
        throw new InvalidTypeException('boom');
    }
}
