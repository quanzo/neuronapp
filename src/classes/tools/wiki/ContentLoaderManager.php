<?php
// src/app/modules/neuron/classes/tools/wiki/ContentLoaderManager.php

namespace app\modules\neuron\classes\tools\wiki;

use Amp\Future;
use Psr\Cache\CacheItemPoolInterface;
use app\modules\neuron\classes\cache\ArrayCache;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;

/**
 * Менеджер загрузчиков контента с поддержкой приоритетов.
 * Управляет несколькими загрузчиками, распределяя URL между ними
 * в соответствии с их способностью обрабатывать определенные типы ссылок.
 * 
 * Поддерживает кеширование через PSR-6 совместимый кеш.
 */
class ContentLoaderManager
{
    /**
     * Массив загрузчиков в порядке приоритета
     * @var ContentLoaderInterface[]
     */
    protected array $loaders = [];

    /**
     * Пул кеша PSR-6
     * @var CacheItemPoolInterface
     */
    protected CacheItemPoolInterface $cache;

    /**
     * Конструктор менеджера загрузчиков.
     * Если кеш не передан, создается ArrayCache с лимитом 100.
     *
     * @param ContentLoaderInterface[] $loaders Массив загрузчиков в порядке приоритета
     * @param CacheItemPoolInterface|null $cache Пул кеша (PSR-6) или null для создания ArrayCache с лимитом 100
     */
    public function __construct(array $loaders = [], ?CacheItemPoolInterface $cache = null)
    {
        $this->setLoaders($loaders);
        $this->cache = $cache ?? new ArrayCache(100);
    }

    /**
     * Устанавливает загрузчики в порядке приоритета.
     * Загрузчики проверяются в том порядке, в котором они переданы в массиве.
     *
     * @param ContentLoaderInterface[] $loaders Массив загрузчиков
     * @return self
     */
    public function setLoaders(array $loaders): self
    {
        $this->loaders = [];
        
        foreach ($loaders as $loader) {
            if ($loader instanceof ContentLoaderInterface) {
                $this->addLoader($loader);
            } else {
                throw new \InvalidArgumentException(
                    'Все загрузчики должны реализовывать ContentLoaderInterface'
                );
            }
        }
        
        return $this;
    }

    /**
     * Добавляет загрузчик в конец списка (с наименьшим приоритетом).
     *
     * @param ContentLoaderInterface $loader Загрузчик для добавления
     * @return self
     */
    public function addLoader(ContentLoaderInterface $loader): self
    {
        $this->loaders[] = $loader;
        return $this;
    }

    /**
     * Добавляет загрузчик в начало списка (с наивысшим приоритетом).
     *
     * @param ContentLoaderInterface $loader Загрузчик для добавления
     * @return self
     */
    public function prependLoader(ContentLoaderInterface $loader): self
    {
        array_unshift($this->loaders, $loader);
        return $this;
    }

    /**
     * Загружает содержимое для одного URL.
     * Находит подходящий загрузчик на основе приоритета и делегирует ему загрузку.
     *
     * @param string $url URL для загрузки
     * @return Future<ArticleContentDto> Future с содержимым страницы
     * @throws \RuntimeException Если не найден подходящий загрузчик
     */
    public function load(string $url): Future
    {
        return \Amp\async(function () use ($url) {
            // Проверяем кеш
            $cacheKey = $this->generateCacheKey($url);
            $cacheItem = $this->cache->getItem($cacheKey);
            
            if ($cacheItem->isHit()) {
                $cachedContent = $cacheItem->get();
                if ($cachedContent instanceof ArticleContentDto) {
                    return $cachedContent;
                }
            }
            
            // Находим подходящий загрузчик
            $loader = $this->findLoaderForUrl($url);
            
            if (!$loader) {
                throw new \RuntimeException("Не найден подходящий загрузчик для URL: {$url}");
            }
            
            // Загружаем контент
            $content = $loader->load($url)->await();
            
            // Сохраняем в кеш (без срока истечения)
            $cacheItem->set($content);
            $this->cache->save($cacheItem);
            
            return $content;
        });
    }

    /**
     * Загружает содержимое для нескольких URL одновременно.
     * Распределяет URL между загрузчиками и выполняет параллельную загрузку.
     *
     * @param string[] $urls Массив URL для загрузки
     * @return Future<array> Future, которое разрешится в массив [url => ArticleContentDto]
     */
    public function loadMultiple(array $urls): Future
    {
        return \Amp\async(function () use ($urls) {
            // Группируем URL по загрузчикам
            $loaderGroups = $this->groupUrlsByLoader($urls);
            
            // Сначала проверяем кеш для всех URL
            $cachedResults = [];
            $urlsToLoad = [];
            
            foreach ($urls as $url) {
                $cacheKey = $this->generateCacheKey($url);
                $cacheItem = $this->cache->getItem($cacheKey);
                
                if ($cacheItem->isHit()) {
                    $cachedContent = $cacheItem->get();
                    if ($cachedContent instanceof ArticleContentDto) {
                        $cachedResults[$url] = $cachedContent;
                        continue;
                    }
                }
                
                $urlsToLoad[] = $url;
            }
            
            // Запускаем загрузку для URL, которых нет в кеше
            $futures = [];
            
            foreach ($loaderGroups as $loaderIndex => $urlGroup) {
                $loader = $this->loaders[$loaderIndex];
                
                foreach ($urlGroup as $url) {
                    // Пропускаем URL, которые уже есть в кеше
                    if (isset($cachedResults[$url])) {
                        continue;
                    }
                    
                    // Пропускаем URL, которые не нужно загружать
                    if (!in_array($url, $urlsToLoad, true)) {
                        continue;
                    }
                    
                    $futures[$url] = $loader->load($url);
                }
            }
            
            // Ожидаем завершения всех загрузок
            $loadedResults = Future\await($futures);
            
            // Сохраняем загруженные результаты в кеш
            foreach ($loadedResults as $url => $content) {
                if ($content instanceof ArticleContentDto) {
                    $cacheKey = $this->generateCacheKey($url);
                    $cacheItem = $this->cache->getItem($cacheKey);
                    $cacheItem->set($content);
                    $this->cache->save($cacheItem);
                }
            }
            
            // Объединяем кешированные и загруженные результаты
            return array_merge($cachedResults, $loadedResults);
        });
    }

    /**
     * Генерирует ключ кеша для URL.
     * Использует хеширование для создания короткого и безопасного ключа.
     *
     * @param string $url URL для кеширования
     * @return string Ключ кеша
     */
    protected function generateCacheKey(string $url): string
    {
        return 'content_' . md5($url);
    }

    /**
     * Находит подходящий загрузчик для указанного URL.
     * Проверяет загрузчики в порядке приоритета.
     *
     * @param string $url URL для проверки
     * @return ContentLoaderInterface|null Подходящий загрузчик или null
     */
    protected function findLoaderForUrl(string $url): ?ContentLoaderInterface
    {
        foreach ($this->loaders as $loader) {
            if ($loader->canLoad($url)) {
                return $loader;
            }
        }
        
        return null;
    }

    /**
     * Группирует URL по индексам загрузчиков, которые могут их обработать.
     *
     * @param string[] $urls Массив URL
     * @return array Массив групп [индекс_загрузчика => [url1, url2, ...]]
     */
    protected function groupUrlsByLoader(array $urls): array
    {
        $groups = [];
        
        foreach ($urls as $url) {
            $loaderIndex = $this->findLoaderIndexForUrl($url);
            
            if ($loaderIndex !== null) {
                if (!isset($groups[$loaderIndex])) {
                    $groups[$loaderIndex] = [];
                }
                
                $groups[$loaderIndex][] = $url;
            }
        }
        
        return $groups;
    }

    /**
     * Находит индекс подходящего загрузчика для URL.
     *
     * @param string $url URL для проверки
     * @return int|null Индекс загрузчика или null
     */
    protected function findLoaderIndexForUrl(string $url): ?int
    {
        foreach ($this->loaders as $index => $loader) {
            if ($loader->canLoad($url)) {
                return $index;
            }
        }
        
        return null;
    }

    /**
     * Возвращает объект кеша.
     *
     * @return CacheItemPoolInterface Пул кеша
     */
    public function getCache(): CacheItemPoolInterface
    {
        return $this->cache;
    }

    /**
     * Устанавливает объект кеша.
     *
     * @param CacheItemPoolInterface $cache Пул кеша (PSR-6)
     * @return self
     */
    public function setCache(CacheItemPoolInterface $cache): self
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * Очищает кеш загруженного контента.
     *
     * @return self
     */
    public function clearCache(): self
    {
        $this->cache->clear();
        return $this;
    }

    /**
     * Возвращает статистику кеша.
     *
     * @return array Статистика кеша
     */
    public function getCacheStats(): array
    {
        if ($this->cache instanceof ArrayCache) {
            return $this->cache->getStats();
        }
        
        // Для других реализаций CacheItemPoolInterface возвращаем базовую статистику
        return [
            'cache_class' => get_class($this->cache),
            'psr6_compliant' => true,
        ];
    }

    /**
     * Возвращает список загрузчиков с их приоритетами.
     *
     * @return ContentLoaderInterface[] Массив загрузчиков
     */
    public function getLoaders(): array
    {
        return $this->loaders;
    }
}
