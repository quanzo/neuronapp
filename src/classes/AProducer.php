<?php

declare(strict_types=1);

namespace app\modules\neuron\classes;

use app\modules\neuron\classes\dir\DirPriority;

/**
 * Базовый класс производителей сущностей по имени.
 *
 * Ищет файлы в поддиректории (имя задаётся через {@see getStorageDirName()})
 * в приоритетных директориях через {@see DirPriority}, кеширует результат
 * и создаёт объект через {@see createFromFile()}.
 */
abstract class AProducer
{
    /**
     * Приоритетный список директорий для поиска файлов.
     */
    protected DirPriority $dirPriority;

    /**
     * Кеш созданных сущностей по имени.
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    public function __construct(DirPriority $dirPriority)
    {
        $this->dirPriority = $dirPriority;
    }

    /**
     * Возвращает имя поддиректории хранения (agents, todos, skills и т.д.).
     */
    abstract public static function getStorageDirName(): string;

    /**
     * Возвращает список расширений файлов для поиска (в порядке приоритета).
     *
     * @return list<string>
     */
    abstract protected function getExtensions(): array;

    /**
     * Создаёт сущность по пути к файлу и имени.
     *
     * @param string $path Абсолютный путь к найденному файлу.
     * @param string $name Имя сущности (без расширения).
     *
     * @return mixed Экземпляр сущности или null при ошибке.
     */
    abstract protected function createFromFile(string $path, string $name): mixed;

    /**
     * Ищет файл сущности в приоритетных директориях.
     *
     * @param string $name Имя сущности (без расширения или с расширением).
     *
     * @return string|null Абсолютный путь к файлу или null.
     */
    protected function resolveItemFile(string $name): ?string
    {
        $relPath = static::getStorageDirName() . '/' . $name;

        return $this->dirPriority->resolveFile($relPath, $this->getExtensions());
    }

    /**
     * Проверяет существование сущности с указанным именем.
     *
     * @param string $name Имя сущности (без расширения файла).
     */
    public function exist(string $name): bool
    {
        return $this->resolveItemFile($name) !== null;
    }

    /**
     * Возвращает сущность по имени (из кеша или создаёт из файла).
     *
     * @param string $name Имя сущности (соответствует имени файла без расширения).
     *
     * @return mixed Экземпляр сущности или null.
     */
    public function get(string $name): mixed
    {
        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }

        $path = $this->resolveItemFile($name);

        if ($path === null) {
            $this->cache[$name] = null;

            return null;
        }

        $entity = $this->createFromFile($path, $name);
        $this->cache[$name] = $entity;

        return $entity;
    }
}
