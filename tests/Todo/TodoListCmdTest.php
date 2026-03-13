<?php

declare(strict_types=1);

namespace Tests\Todo;

use app\modules\neuron\classes\todo\Todo;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для получения списка команд из текста Todo.
 */
class TodoListCmdTest extends TestCase
{
    /**
     * Задание без команд возвращает пустой список CmdDto.
     */
    public function testGetCmdListEmpty(): void
    {
        $todo = Todo::fromString('Do something');

        $this->assertSame([], $todo->getCmdList());
    }

    /**
     * Команды извлекаются из текста задания, при этом одиночный символ '@'
     * не считается префиксом команды.
     */
    public function testGetCmdListWithCommands(): void
    {
        $text = <<<'TXT'
First @@func("A", 1)
Second text@func(1, 2) text
Third @@unknown(2,3) and @@FUNC("UP", 10)
TXT;

        $todo = Todo::fromString($text);

        $cmds = $todo->getCmdList();

        $this->assertCount(3, $cmds);
        $this->assertSame('func', $cmds[0]->getName());
        $this->assertSame(['A', 1], $cmds[0]->getParams());

        $this->assertSame('unknown', $cmds[1]->getName());
        $this->assertSame([2, 3], $cmds[1]->getParams());

        $this->assertSame('FUNC', $cmds[2]->getName());
        $this->assertSame(['UP', 10], $cmds[2]->getParams());
    }
}
