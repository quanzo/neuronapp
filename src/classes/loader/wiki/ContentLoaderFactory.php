<?php

// src/app/modules/neuron/classes/loader/wiki/ContentLoaderFactory.php

namespace app\modules\neuron\classes\loader\wiki;

use app\modules\neuron\classes\cache\ArrayCache;
use app\modules\neuron\classes\loader\ollama\OllamaWebFetchLoader;
use app\modules\neuron\classes\loader\web\GenericWebLoader;
use app\modules\neuron\interfaces\ContentLoaderInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Фабрика для создания менеджера загрузчиков контента с предустановленными загрузчиками.
 * Предоставляет удобные методы для создания стандартных конфигураций.
 */
class ContentLoaderFactory
{
    /**
     * Создает менеджер загрузчиков со стандартной конфигурацией:
     * 1. WikipediaLoader (высший приоритет)
     * 2. RuWikiLoader (средний приоритет)
     * 3. GenericWebLoader (низший приоритет, загрузчик по умолчанию)
     *
     * @param int $cacheLimit Лимит кеша (по умолчанию 100)
     * @return ContentLoaderManager Менеджер загрузчиков
     */
    public static function createDefaultManager(int $cacheLimit = 100): ContentLoaderManager
    {
        return new ContentLoaderManager([
            new WikipediaLoader(),
            new RuWikiLoader(),
            new GenericWebLoader(),
        ], new ArrayCache($cacheLimit));
    }

    /**
     * Создает менеджер загрузчиков с кастомным кешем.
     *
     * @param CacheItemPoolInterface $cache Пользовательский кеш (PSR-6)
     * @return ContentLoaderManager Менеджер загрузчиков
     */
    public static function createDefaultManagerWithCustomCache(CacheItemPoolInterface $cache): ContentLoaderManager
    {
        return new ContentLoaderManager([
            new WikipediaLoader(),
            new RuWikiLoader(),
            new GenericWebLoader(),
        ], $cache);
    }

    /**
     * Создает менеджер загрузчиков только для Wikipedia.
     * Полезно, когда нужно работать исключительно с Wikipedia.
     *
     * @param int $cacheLimit Лимит кеша (по умолчанию 100)
     * @return ContentLoaderManager Менеджер загрузчиков
     */
    public static function createWikipediaOnlyManager(int $cacheLimit = 100): ContentLoaderManager
    {
        return new ContentLoaderManager([
            new WikipediaLoader(),
        ], new ArrayCache($cacheLimit));
    }

    /**
     * Создает менеджер загрузчиков только для RuWiki.
     * Полезно, когда нужно работать исключительно с RuWiki.
     *
     * @param int $cacheLimit Лимит кеша (по умолчанию 100)
     * @return ContentLoaderManager Менеджер загрузчиков
     */
    public static function createRuWikiOnlyManager(int $cacheLimit = 100): ContentLoaderManager
    {
        return new ContentLoaderManager([
            new RuWikiLoader(),
        ], new ArrayCache($cacheLimit));
    }

    /**
     * Создает менеджер загрузчиков для произвольных сайтов.
     * Использует только GenericWebLoader.
     *
     * @param int $cacheLimit Лимит кеша (по умолчанию 100)
     * @return ContentLoaderManager Менеджер загрузчиков
     */
    public static function createGenericOnlyManager(int $cacheLimit = 100): ContentLoaderManager
    {
        return new ContentLoaderManager([
            new GenericWebLoader(),
        ], new ArrayCache($cacheLimit));
    }

    /**
     * Создает кастомный менеджер загрузчиков.
     * Позволяет полностью контролировать порядок и состав загрузчиков.
     *
     * @param ContentLoaderInterface[] $loaders Массив загрузчиков в порядке приоритета
     * @param CacheItemPoolInterface|null $cache Пул кеша или null для ArrayCache с лимитом 100
     * @return ContentLoaderManager Менеджер загрузчиков
     */
    public static function createCustomManager(array $loaders, ?CacheItemPoolInterface $cache = null): ContentLoaderManager
    {
        return new ContentLoaderManager($loaders, $cache);
    }

    /**
     * Создает менеджер загрузчиков с Ollama Web Fetch в качестве основного загрузчика.
     */
    public static function createOllamaEnhancedManager(
        string $apiKey = '',
        int $cacheLimit = 100
    ): ContentLoaderManager {
        $ollamaService = new \app\modules\neuron\services\ollama\OllamaApiService(
            'https://ollama.com',
            $apiKey
        );

        return new ContentLoaderManager([
            new WikipediaLoader(),
            new RuWikiLoader(),
            new OllamaWebFetchLoader($ollamaService, true), // С fallback на GenericWebLoader
        ], new ArrayCache($cacheLimit));
    }

    /**
     * Создает менеджер загрузчиков только с Ollama Web Fetch.
     */
    public static function createOllamaOnlyManager(
        string $apiKey = '',
        int $cacheLimit = 100
    ): ContentLoaderManager {
        $ollamaService = new \app\modules\neuron\services\ollama\OllamaApiService(
            'https://ollama.com',
            $apiKey
        );

        return new ContentLoaderManager([
            new OllamaWebFetchLoader($ollamaService, false),
        ], new ArrayCache($cacheLimit));
    }
}
