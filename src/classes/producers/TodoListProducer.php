<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\producers;

use app\modules\neuron\classes\AProducer;
use app\modules\neuron\classes\todo\TodoList;

/**
 * Фабрика списков заданий Todo по имени.
 *
 * Ищет файлы в поддиректории "todos" через {@see DirPriority}
 * и создаёт экземпляры {@see TodoList} из содержимого файла.
 * Поддерживаются расширения: .txt, .md.
 */
class TodoListProducer extends AProducer
{
    /**
     * @inheritDoc
     */
    public static function getStorageDirName(): string
    {
        return 'todos';
    }

    /**
     * @inheritDoc
     *
     * @return list<string>
     */
    protected function getExtensions(): array
    {
        return ['txt', 'md'];
    }

    /**
     * @inheritDoc
     */
    protected function createFromFile(string $path, string $name): ?TodoList
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return new TodoList($contents);
    }

    /**
     * Возвращает список заданий по имени.
     *
     * @param string $name Имя списка (соответствует имени файла без расширения).
     *
     * @return TodoList|null Экземпляр списка заданий или null.
     */
    public function get(string $name): ?TodoList
    {
        $result = parent::get($name);

        return $result instanceof TodoList ? $result : null;
    }
}
