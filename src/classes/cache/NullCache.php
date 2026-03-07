<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Пустая реализация пула кеша (PSR-6).
 *
 * Полностью реализует CacheItemPoolInterface, но не сохраняет данные:
 * все операции чтения возвращают «промах», операции записи/удаления не выполняют действий.
 */
class NullCache implements CacheItemPoolInterface
{
    /**
     * Возвращает элемент кеша по ключу.
     * Всегда возвращает элемент с isHit = false.
     *
     * @param string $key Ключ элемента
     * @return CacheItemInterface Элемент кеша (не найден)
     * @throws InvalidArgumentException Если ключ невалиден
     */
    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);
        $item = new CacheItem($key);
        $item->setIsHit(false);
        return $item;
    }

    /**
     * Возвращает набор элементов кеша по ключам.
     *
     * @param string[] $keys Массив ключей
     * @return iterable<string, CacheItemInterface> Элементы кеша (все с isHit = false)
     * @throws InvalidArgumentException Если какой-либо ключ невалиден
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }
        return $items;
    }

    /**
     * Проверяет наличие элемента в кеше.
     * Всегда возвращает false.
     *
     * @param string $key Ключ элемента
     * @return bool Всегда false
     * @throws InvalidArgumentException Если ключ невалиден
     */
    public function hasItem(string $key): bool
    {
        $this->validateKey($key);
        return false;
    }

    /**
     * Очищает весь кеш. Ничего не делает.
     *
     * @return bool Всегда true
     */
    public function clear(): bool
    {
        return true;
    }

    /**
     * Удаляет элемент из кеша. Ничего не делает.
     *
     * @param string $key Ключ элемента
     * @return bool Всегда true
     * @throws InvalidArgumentException Если ключ невалиден
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        return true;
    }

    /**
     * Удаляет несколько элементов из кеша. Ничего не делает.
     *
     * @param string[] $keys Массив ключей
     * @return bool Всегда true
     * @throws InvalidArgumentException Если какой-либо ключ невалиден
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }
        return true;
    }

    /**
     * Сохраняет элемент в кеше. Ничего не делает.
     *
     * @param CacheItemInterface $item Элемент кеша
     * @return bool Всегда true
     */
    public function save(CacheItemInterface $item): bool
    {
        return true;
    }

    /**
     * Откладывает сохранение элемента. Ничего не делает.
     *
     * @param CacheItemInterface $item Элемент кеша
     * @return bool Всегда true
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return true;
    }

    /**
     * Сохраняет все отложенные элементы. Ничего не делает.
     *
     * @return bool Всегда true
     */
    public function commit(): bool
    {
        return true;
    }

    /**
     * Валидирует ключ кеша по правилам PSR-6.
     *
     * @param string $key Ключ для проверки
     * @throws InvalidArgumentException Если ключ невалиден
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Ключ кеша должен быть непустой строкой');
        }
        if (preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
            throw new \InvalidArgumentException('Ключ кеша содержит недопустимые символы: {}()/\\@:');
        }
    }
}
