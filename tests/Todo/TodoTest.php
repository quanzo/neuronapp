<?php

declare(strict_types=1);

namespace Tests\Todo;

use app\modules\neuron\classes\todo\Todo;
use app\modules\neuron\interfaces\ITodo;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see Todo}.
 *
 * Todo — класс одного задания (элемент списка TodoList).
 * Хранит многострочный текст задачи и предоставляет статический конструктор
 * fromString() для создания экземпляров. При получении текста через getTodo()
 * поддерживает подстановку именованных параметров ($param → значение).
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\todo\Todo}
 */
class TodoTest extends TestCase
{
    /**
     * Класс Todo реализует интерфейс ITodo.
     */
    public function testImplementsInterface(): void
    {
        $todo = Todo::fromString('task');
        $this->assertInstanceOf(ITodo::class, $todo);
    }

    /**
     * Простой текст задания без переводов строк возвращается как есть.
     */
    public function testFromStringSimple(): void
    {
        $todo = Todo::fromString('Buy milk');
        $this->assertSame('Buy milk', $todo->getTodo());
    }

    /**
     * Различные форматы переводов строк (\r\n, \r) нормализуются к \n.
     */
    public function testFromStringNormalizesLineEndings(): void
    {
        $todo = Todo::fromString("line1\r\nline2\rline3\nline4");
        $this->assertSame("line1\nline2\nline3\nline4", $todo->getTodo());
    }

    /**
     * Пустая строка — допустимый вход, задание с пустым текстом.
     */
    public function testFromStringEmptyString(): void
    {
        $todo = Todo::fromString('');
        $this->assertSame('', $todo->getTodo());
    }

    /**
     * Многострочный текст сохраняется полностью.
     */
    public function testFromStringMultiLine(): void
    {
        $todo = Todo::fromString("line1\nline2\nline3");
        $this->assertSame("line1\nline2\nline3", $todo->getTodo());
    }

    /**
     * getTodo(null) — параметры не передаются, плейсхолдеры остаются как есть.
     */
    public function testGetTodoWithNullParams(): void
    {
        $todo = Todo::fromString('task $query');
        $this->assertSame('task $query', $todo->getTodo(null));
    }

    /**
     * getTodo с массивом параметров — плейсхолдер $query заменяется значением.
     */
    public function testGetTodoWithParams(): void
    {
        $todo = Todo::fromString('Search for $query');
        $result = $todo->getTodo(['query' => 'cats']);
        $this->assertSame('Search for cats', $result);
    }

    /**
     * Отсутствующий параметр — плейсхолдер заменяется пустой строкой.
     */
    public function testGetTodoWithMissingParams(): void
    {
        $todo = Todo::fromString('Search for $query');
        $result = $todo->getTodo([]);
        $this->assertSame('Search for ', $result);
    }

    /**
     * Текст без плейсхолдеров + пустой массив параметров — текст без изменений.
     */
    public function testGetTodoWithEmptyParams(): void
    {
        $todo = Todo::fromString('No placeholders');
        $result = $todo->getTodo([]);
        $this->assertSame('No placeholders', $result);
    }

    /**
     * Подстановка нескольких параметров одновременно.
     */
    public function testGetTodoWithMultipleParams(): void
    {
        $todo = Todo::fromString('$action on $target');
        $result = $todo->getTodo(['action' => 'Click', 'target' => 'button']);
        $this->assertSame('Click on button', $result);
    }

    /**
     * Ведущие и внутренние пробелы сохраняются (не обрезаются).
     */
    public function testFromStringPreservesWhitespace(): void
    {
        $todo = Todo::fromString("  leading spaces  \n  more spaces  ");
        $this->assertSame("  leading spaces  \n  more spaces  ", $todo->getTodo());
    }
}
