<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\producers;

use app\modules\neuron\classes\AProducer;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\classes\dir\DirPriority;

/**
 * Фабрика списков заданий Todo по имени.
 *
 * Ищет файлы в поддиректории "todos" через {@see DirPriority}
 * и создаёт экземпляры {@see TodoList} из содержимого файла.
 * Поддерживаются расширения: .txt, .md.
 */
class TodoListProducer extends AProducer
{
    public const STORAGE_DIR_NAME = 'todos';

    /**
     * @var list<string>
     */
    public const EXTENSIONS = ['txt', 'md'];

    /**
     * @inheritDoc
     */
    public static function getStorageDirName(): string
    {
        return self::STORAGE_DIR_NAME;
    }

    /**
     * @inheritDoc
     *
     * @return list<string>
     */
    protected function getExtensions(): array
    {
        return self::EXTENSIONS;
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

        return new TodoList($contents, $name, $this->getConfigurationApp());
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
