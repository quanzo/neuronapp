<?php

declare(strict_types=1);

namespace Tests\Producers;

use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\producers\TodoListProducer;
use app\modules\neuron\classes\todo\TodoList;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see TodoListProducer}.
 *
 * TodoListProducer — фабрика списков заданий (TodoList) по имени.
 * Ищет файлы в поддиректории «todos/» через DirPriority.
 * Поддерживаемые расширения: .txt (приоритет), .md.
 * Результат кешируется по имени списка.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\producers\TodoListProducer}
 */
class TodoListProducerTest extends TestCase
{
    /** @var string Временная директория с подкаталогом todos/. */
    private string $tmpDir;

    /**
     * Создаёт временную директорию с подкаталогом todos/.
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_todoprod_' . uniqid();
        mkdir($this->tmpDir . '/todos', 0777, true);
    }

    /**
     * Удаляет временную директорию.
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /**
     * Рекурсивное удаление директории.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Имя поддиректории хранения — «todos».
     */
    public function testGetStorageDirName(): void
    {
        $this->assertSame('todos', TodoListProducer::getStorageDirName());
    }

    /**
     * Несуществующий список — exist() возвращает false.
     */
    public function testExistReturnsFalseForMissing(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new TodoListProducer($dp);
        $this->assertFalse($producer->exist('nonexistent'));
    }

    /**
     * Файл .txt в todos/ — exist() возвращает true.
     */
    public function testExistReturnsTrueForTxt(): void
    {
        file_put_contents($this->tmpDir . '/todos/tasks.txt', '1. Do stuff');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new TodoListProducer($dp);
        $this->assertTrue($producer->exist('tasks'));
    }

    /**
     * Файл .md в todos/ — exist() возвращает true.
     */
    public function testExistReturnsTrueForMd(): void
    {
        file_put_contents($this->tmpDir . '/todos/tasks.md', '1. Do stuff');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new TodoListProducer($dp);
        $this->assertTrue($producer->exist('tasks'));
    }

    /**
     * Несуществующий список — get() возвращает null.
     */
    public function testGetReturnsNullForMissing(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new TodoListProducer($dp);
        $this->assertNull($producer->get('missing'));
    }

    /**
     * Существующий .txt-файл с двумя заданиями — get() возвращает TodoList
     * с правильным именем и количеством заданий.
     */
    public function testGetReturnsTodoList(): void
    {
        file_put_contents($this->tmpDir . '/todos/mylist.txt', "1. Task one\n2. Task two");
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new TodoListProducer($dp);

        $list = $producer->get('mylist');
        $this->assertInstanceOf(TodoList::class, $list);
        $this->assertSame('mylist', $list->getName());
        $this->assertCount(2, $list->getTodos());
    }

    /**
     * Повторный вызов get() возвращает тот же объект из кеша.
     */
    public function testGetCachesResult(): void
    {
        file_put_contents($this->tmpDir . '/todos/cached.txt', '1. Task');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new TodoListProducer($dp);

        $first = $producer->get('cached');
        $second = $producer->get('cached');
        $this->assertSame($first, $second);
    }

    /**
     * При наличии и .txt, и .md — файл .txt имеет приоритет.
     */
    public function testTxtPriorityOverMd(): void
    {
        file_put_contents($this->tmpDir . '/todos/dual.txt', '1. From TXT');
        file_put_contents($this->tmpDir . '/todos/dual.md', '1. From MD');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new TodoListProducer($dp);

        $list = $producer->get('dual');
        $this->assertSame('From TXT', $list->getTodos()[0]->getTodo());
    }

    /**
     * Файл с блоком опций — опции корректно парсятся, задания доступны.
     */
    public function testGetWithOptions(): void
    {
        $content = "---\nagent: myAgent\n---\n1. Task one";
        file_put_contents($this->tmpDir . '/todos/opts.txt', $content);
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new TodoListProducer($dp);

        $list = $producer->get('opts');
        $this->assertSame('myAgent', $list->getOptions()['agent']);
        $this->assertCount(1, $list->getTodos());
    }

    /**
     * Файл существует в обеих директориях — берётся из первой (приоритетной).
     */
    public function testPriorityAcrossDirectories(): void
    {
        $dir2 = $this->tmpDir . '/dir2';
        mkdir($dir2 . '/todos', 0777, true);
        file_put_contents($this->tmpDir . '/todos/shared.txt', '1. From primary');
        file_put_contents($dir2 . '/todos/shared.txt', '1. From secondary');

        $dp = new DirPriority([$this->tmpDir, $dir2]);
        $producer = new TodoListProducer($dp);

        $list = $producer->get('shared');
        $this->assertSame('From primary', $list->getTodos()[0]->getTodo());
    }

    /**
     * Список заданий в подкаталоге (sub/deep) — имя включает путь.
     */
    public function testGetWithSubdirectory(): void
    {
        mkdir($this->tmpDir . '/todos/sub', 0777, true);
        file_put_contents($this->tmpDir . '/todos/sub/deep.txt', '1. Deep task');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new TodoListProducer($dp);

        $list = $producer->get('sub/deep');
        $this->assertInstanceOf(TodoList::class, $list);
        $this->assertSame('sub/deep', $list->getName());
    }
}
