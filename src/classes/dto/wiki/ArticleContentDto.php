<?php

// src/app/modules/neuron/dto/wiki/ArticleContentDto.php

namespace app\modules\neuron\classes\dto\wiki;

use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\IArrayable;

/**
 * Data Transfer Object (DTO) для представления полного содержимого статьи.
 * Содержит полный текст статьи и метаданные.
 */
final class ArticleContentDto implements IArrayable
{
    /**
     * Полное содержимое статьи в формате HTML
     * @var string
     */
    public readonly string $content;

    /**
     * Заголовок статьи
     * @var string
     */
    public readonly string $title;

    /**
     * URL исходной статьи
     * @var string
     */
    public readonly string $sourceUrl;

    /**
     * Тип источника данных (enum)
     * @var ContentSourceType
     */
    public readonly ContentSourceType $sourceType;

    /**
     * Метаданные статьи (дополнительная информация)
     * @var array
     */
    public readonly array $metadata;

    /**
     * Конструктор DTO содержимого статьи.
     *
     * @param string $content Полное содержимое статьи в формате HTML
     * @param string $title Заголовок статьи
     * @param string $sourceUrl URL исходной статьи
     * @param ContentSourceType $sourceType Тип источника данных
     * @param array $metadata Метаданные статьи
     */
    public function __construct(
        string $content,
        string $title,
        string $sourceUrl,
        ContentSourceType $sourceType,
        array $metadata = []
    ) {
        $this->content = $content;
        $this->title = $title;
        $this->sourceUrl = $sourceUrl;
        $this->sourceType = $sourceType;
        $this->metadata = $metadata;
    }

    /**
     * Создает DTO из массива данных.
     * Удобно для десериализации из кеша или базы данных.
     *
     * @param array $data Массив данных
     * @return self Новый экземпляр DTO
     * @throws \InvalidArgumentException Если данные некорректны
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['content'], $data['title'], $data['sourceUrl'], $data['sourceType'])) {
            throw new \InvalidArgumentException('Не все обязательные поля присутствуют в данных');
        }

        // Преобразуем строковое значение sourceType в enum
        $sourceType = ContentSourceType::tryFrom($data['sourceType']);

        if (!$sourceType) {
            throw new \InvalidArgumentException(
                sprintf('Некорректный тип источника: %s', $data['sourceType'])
            );
        }

        return new self(
            content   : $data['content'],
            title     : $data['title'],
            sourceUrl : $data['sourceUrl'],
            sourceType: $sourceType,
            metadata  : $data['metadata'] ?? []
        );
    }

    /**
     * Преобразует DTO в массив.
     * Удобно для сериализации в кеш или базу данных.
     *
     * @return array Массив данных
     */
    public function toArray(): array
    {
        return [
            'content'    => $this->content,
            'title'      => $this->title,
            'sourceUrl'  => $this->sourceUrl,
            'sourceType' => $this->sourceType->value,
            'metadata'   => $this->metadata,
        ];
    }

    /**
     * Проверяет, является ли источник вики-энциклопедией.
     *
     * @return bool True, если источник Wikipedia или RuWiki
     */
    public function isWikiSource(): bool
    {
        return $this->sourceType->isWiki();
    }

    /**
     * Проверяет, является ли источник специализированным.
     * Специализированные источники имеют собственные загрузчики.
     *
     * @return bool True, если источник Wikipedia или RuWiki
     */
    public function isSpecializedSource(): bool
    {
        return $this->sourceType->isSpecialized();
    }

    /**
     * Возвращает читаемое название типа источника.
     *
     * @return string Человеко-читаемое название
     */
    public function getSourceTypeLabel(): string
    {
        return $this->sourceType->getLabel();
    }

    /**
     * Возвращает базовый URL источника.
     *
     * @return string|null Базовый URL или null для generic источников
     */
    public function getSourceBaseUrl(): ?string
    {
        return $this->sourceType->getBaseUrl();
    }

    /**
     * Получает значение из метаданных по ключу.
     *
     * @param string $key Ключ метаданных
     * @param mixed $default Значение по умолчанию, если ключ не найден
     * @return mixed Значение метаданных или значение по умолчанию
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Проверяет наличие ключа в метаданных.
     *
     * @param string $key Ключ метаданных
     * @return bool True, если ключ существует
     */
    public function hasMetadata(string $key): bool
    {
        return isset($this->metadata[$key]);
    }

    /**
     * Возвращает все метаданные.
     *
     * @return array Массив метаданных
     */
    public function getAllMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Проверяет, является ли статья загруженной через Ollama.
     *
     * @return bool True, если статья загружена через Ollama
     */
    public function isOllamaSource(): bool
    {
        return $this->getMetadata('ollama_fetched', false) === true ||
               $this->getMetadata('search_source') === 'ollama_web_search';
    }

    /**
     * Проверяет, является ли источник SearXNG поиском.
     * SearXNG - это мета-поисковая система, которая объединяет результаты из разных источников.
     *
     * @return bool True, если статья найдена через SearXNG
     */
    public function isSearxngSource(): bool
    {
        return $this->sourceType === ContentSourceType::SEARXNG ||
               $this->getMetadata('search_source') === 'searxng' ||
               $this->getMetadata('searxng_fetched', false) === true;
    }

    /**
     * Возвращает оригинальный тип источника, если статья была найдена через SearXNG.
     * SearXNG сам не является источником контента, а лишь поисковой системой.
     *
     * @return ContentSourceType|null Оригинальный тип источника или null, если не определен
     */
    public function getOriginalSourceType(): ?ContentSourceType
    {
        if ($this->isSearxngSource()) {
            $originalType = $this->getMetadata('original_source_type');
            if ($originalType && ContentSourceType::isValid($originalType)) {
                return ContentSourceType::from($originalType);
            }

            // Пытаемся определить по URL
            return ContentSourceType::fromUrl($this->sourceUrl);
        }

        return $this->sourceType;
    }

    /**
     * Получает информацию о поисковой системе SearXNG, если статья найдена через неё.
     *
     * @return array|null Информация о SearXNG или null, если не применимо
     */
    public function getSearxngInfo(): ?array
    {
        if (!$this->isSearxngSource()) {
            return null;
        }

        return [
            'score' => $this->getMetadata('searxng_score'),
            'snippet' => $this->getMetadata('searxng_snippet'),
            'content_type' => $this->getMetadata('searxng_content_type'),
            'engine' => $this->getMetadata('searxng_engine'),
            'server' => $this->getMetadata('searxng_server'),
            'fetched_at' => $this->getMetadata('searxng_fetched_at'),
            'original_source_type' => $this->getOriginalSourceType()?->value,
        ];
    }
}
