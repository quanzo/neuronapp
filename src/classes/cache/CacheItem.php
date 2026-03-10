<?php

namespace app\modules\neuron\classes\cache;

use Psr\Cache\CacheItemInterface;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;

/**
 * Элемент кеша, соответствующий PSR-6 CacheItemInterface.
 * Хранит одно значение в кеше с метаданными (время истечения, теги и т.д.).
 */
class CacheItem implements CacheItemInterface
{
    /**
     * Ключ элемента кеша
     * @var string
     */
    private string $key;

    /**
     * Значение элемента кеша
     * @var mixed
     */
    private $value;

    /**
     * Флаг, указывающий, является ли элемент попаданием в кеш
     * @var bool
     */
    private bool $isHit = false;

    /**
     * Время истечения срока действия элемента кеша (timestamp)
     * @var int|null
     */
    private ?int $expiresAt = null;

    /**
     * Конструктор элемента кеша.
     *
     * @param string $key Ключ элемента кеша
     */
    public function __construct(string $key)
    {
        $this->key = $key;
    }

    /**
     * Возвращает ключ элемента кеша.
     *
     * @return string Ключ элемента
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Возвращает значение элемента кеша.
     * Обрабатывает специальные случаи, такие как ArticleContentDto с Enum.
     *
     * @return mixed Значение элемента или null, если элемент не найден
     */
    public function get(): mixed
    {
        if (!$this->isHit()) {
            return null;
        }

        // Обработка ArticleContentDto для корректного восстановления Enum
        if ($this->value instanceof ArticleContentDto) {
            return $this->value;
        }

        // Обработка массива, который может быть результатом toArray() ArticleContentDto
        if (
            is_array($this->value) &&
            isset(
                $this->value['content'],
                $this->value['title'],
                $this->value['sourceUrl'],
                $this->value['sourceType']
            )
        ) {
            try {
                return ArticleContentDto::fromArray($this->value);
            } catch (\InvalidArgumentException $e) {
                // Если не удалось создать ArticleContentDto, возвращаем массив как есть
                return $this->value;
            }
        }

        return $this->value;
    }

    /**
     * Устанавливает значение элемента кеша.
     * Обрабатывает специальные случаи, такие как ArticleContentDto.
     *
     * @param mixed $value Значение для кеширования
     * @return static
     */
    public function set(mixed $value): static
    {
        // Если это ArticleContentDto, преобразуем в массив для лучшей сериализации
        if ($value instanceof ArticleContentDto) {
            $this->value = $value->toArray();
        } else {
            $this->value = $value;
        }

        $this->isHit = true;
        return $this;
    }

    /**
     * Проверяет, является ли элемент попаданием в кеш.
     *
     * @return bool True, если элемент найден в кеше и еще не истек его срок действия
     */
    public function isHit(): bool
    {
        if (!$this->isHit) {
            return false;
        }

        // Проверяем срок действия
        if ($this->expiresAt !== null && time() > $this->expiresAt) {
            $this->isHit = false;
            return false;
        }

        return true;
    }

    /**
     * Устанавливает абсолютное время истечения срока действия элемента.
     *
     * @param \DateTimeInterface|null $expiration Время истечения или null для отмены истечения
     * @return static
     */
    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration ? $expiration->getTimestamp() : null;
        return $this;
    }

    /**
     * Устанавливает относительное время истечения срока действия элемента.
     *
     * @param int|\DateInterval|null $time Интервал времени в секундах или объект DateInterval
     * @return static
     */
    public function expiresAfter(int|\DateInterval|null $time): static
    {
        if ($time === null) {
            $this->expiresAt = null;
        } elseif (is_int($time)) {
            $this->expiresAt = time() + $time;
        } elseif ($time instanceof \DateInterval) {
            $this->expiresAt = (new \DateTime())->add($time)->getTimestamp();
        }

        return $this;
    }

    /**
     * Устанавливает флаг isHit.
     *
     * @param bool $isHit Флаг попадания в кеш
     * @return static
     */
    public function setIsHit(bool $isHit): static
    {
        $this->isHit = $isHit;
        return $this;
    }

    /**
     * Возвращает время истечения срока действия.
     *
     * @return int|null Timestamp или null
     */
    public function getExpiresAt(): ?int
    {
        return $this->expiresAt;
    }
}
