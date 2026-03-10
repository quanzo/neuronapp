<?php

namespace app\modules\neuron\classes\cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Реализация пула кеша на основе массива, соответствующая PSR-6.
 * Поддерживает ограничение по количеству элементов (LRU алгоритм).
 */
class ArrayCache implements CacheItemPoolInterface
{
    /**
     * Массив для хранения элементов кеша [ключ => CacheItem]
     * @var CacheItem[]
     */
    private array $items = [];

    /**
     * Массив для отслеживания порядка использования элементов (LRU)
     * @var string[]
     */
    private array $usageOrder = [];

    /**
     * Максимальное количество элементов в кеше
     * @var int
     */
    private int $limit;

    /**
     * Элементы, ожидающие сохранения (отложенное сохранение)
     * @var CacheItem[]
     */
    private array $deferred = [];

    /**
     * Конструктор кеша на основе массива.
     *
     * @param int $limit Максимальное количество элементов в кеше
     */
    public function __construct(int $limit = 100)
    {
        $this->limit = max(1, $limit); // Гарантируем хотя бы 1 элемент
    }

    /**
     * Возвращает элемент кеша по ключу.
     *
     * @param string $key Ключ элемента
     * @return CacheItemInterface Элемент кеша
     * @throws InvalidArgumentException Если ключ невалиден
     */
    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);

        // Обновляем порядок использования для LRU
        $this->updateUsageOrder($key);

        if (isset($this->items[$key])) {
            $item = $this->items[$key];

            // Проверяем, не истек ли срок действия
            if ($item->isHit()) {
                return clone $item; // Возвращаем клон для безопасности
            } else {
                // Удаляем просроченный элемент
                unset($this->items[$key]);
            }
        }

        // Создаем новый элемент (не найден в кеше)
        $item = new CacheItem($key);
        $item->setIsHit(false);

        return $item;
    }

    /**
     * Возвращает набор элементов кеша по ключам.
     *
     * @param string[] $keys Массив ключей
     * @return iterable Массив элементов кеша [ключ => CacheItemInterface]
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
     * Проверяет, существует ли элемент в кеше и не истек ли его срок.
     *
     * @param string $key Ключ элемента
     * @return bool True, если элемент существует и валиден
     * @throws InvalidArgumentException Если ключ невалиден
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * Очищает весь кеш.
     *
     * @return bool Всегда true
     */
    public function clear(): bool
    {
        $this->items = [];
        $this->usageOrder = [];
        $this->deferred = [];

        return true;
    }

    /**
     * Удаляет элемент из кеша.
     *
     * @param string $key Ключ элемента
     * @return bool True, если элемент был удален
     * @throws InvalidArgumentException Если ключ невалиден
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);

        if (isset($this->items[$key])) {
            unset($this->items[$key]);
        }

        // Удаляем из порядка использования
        $index = array_search($key, $this->usageOrder, true);
        if ($index !== false) {
            array_splice($this->usageOrder, $index, 1);
        }

        // Удаляем из отложенных элементов
        if (isset($this->deferred[$key])) {
            unset($this->deferred[$key]);
        }

        return true;
    }

    /**
     * Удаляет несколько элементов из кеша.
     *
     * @param string[] $keys Массив ключей
     * @return bool True, если все элементы были удалены
     * @throws InvalidArgumentException Если какой-либо ключ невалиден
     */
    public function deleteItems(array $keys): bool
    {
        $allDeleted = true;

        foreach ($keys as $key) {
            if (!$this->deleteItem($key)) {
                $allDeleted = false;
            }
        }

        return $allDeleted;
    }

    /**
     * Сохраняет элемент в кеше.
     *
     * @param CacheItemInterface $item Элемент кеша
     * @return bool True, если элемент успешно сохранен
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }

        $key = $item->getKey();

        // Обновляем элемент в кеше
        $this->items[$key] = clone $item;

        // Обновляем порядок использования
        $this->updateUsageOrder($key);

        // Обеспечиваем соблюдение лимита
        $this->enforceLimit();

        return true;
    }

    /**
     * Откладывает сохранение элемента (добавляет в очередь).
     *
     * @param CacheItemInterface $item Элемент кеша
     * @return bool Всегда true
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if ($item instanceof CacheItem) {
            $key = $item->getKey();
            $this->deferred[$key] = clone $item;
        }

        return true;
    }

    /**
     * Сохраняет все отложенные элементы.
     *
     * @return bool True, если все элементы успешно сохранены
     */
    public function commit(): bool
    {
        $allSaved = true;

        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                $allSaved = false;
            }
        }

        // Очищаем очередь отложенных элементов
        $this->deferred = [];

        return $allSaved;
    }

    /**
     * Валидирует ключ кеша.
     *
     * @param string $key Ключ для проверки
     * @throws InvalidArgumentException Если ключ невалиден
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Ключ кеша должен быть непустой строкой');
        }

        // Проверяем на недопустимые символы (по PSR-6)
        if (preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new \InvalidArgumentException('Ключ кеша содержит недопустимые символы: {}()/\\@:');
        }
    }

    /**
     * Обновляет порядок использования элементов для LRU алгоритма.
     * Перемещает ключ в конец массива как самый недавно использованный.
     *
     * @param string $key Ключ для обновления
     */
    private function updateUsageOrder(string $key): void
    {
        // Удаляем ключ из текущей позиции
        $index = array_search($key, $this->usageOrder, true);
        if ($index !== false) {
            array_splice($this->usageOrder, $index, 1);
        }

        // Добавляем ключ в конец (самый недавно использованный)
        $this->usageOrder[] = $key;
    }

    /**
     * Обеспечивает соблюдение ограничения по количеству элементов.
     * Удаляет самые старые элементы (LRU), если превышен лимит.
     */
    private function enforceLimit(): void
    {
        while (count($this->items) > $this->limit) {
            // Удаляем самый старый элемент (первый в порядке использования)
            $oldestKey = array_shift($this->usageOrder);
            if ($oldestKey && isset($this->items[$oldestKey])) {
                unset($this->items[$oldestKey]);
            }
        }
    }

    /**
     * Возвращает максимальное количество элементов в кеше.
     *
     * @return int Лимит кеша
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Устанавливает максимальное количество элементов в кеше.
     *
     * @param int $limit Новый лимит кеша
     * @return self
     */
    public function setLimit(int $limit): self
    {
        $this->limit = max(1, $limit);
        $this->enforceLimit(); // Применяем новый лимит немедленно
        return $this;
    }

    /**
     * Возвращает статистику кеша.
     *
     * @return array Статистика кеша
     */
    public function getStats(): array
    {
        $totalSize = 0;
        foreach ($this->items as $item) {
            $value = $item->get();
            if (is_string($value)) {
                $totalSize += strlen($value);
            }
        }

        return [
            'items_count' => count($this->items),
            'limit' => $this->limit,
            'deferred_count' => count($this->deferred),
            'total_size_bytes' => $totalSize,
            'usage_order_count' => count($this->usageOrder),
        ];
    }
}
