<?php
// src/app/modules/neuron/classes/tools/wiki/search/ArticleSearchFactory.php

namespace app\modules\neuron\classes\tools\wiki\search;

use app\modules\neuron\classes\tools\ollama\search\OllamaArticleSearcher;
use app\modules\neuron\classes\tools\wiki\RuWikiLoader;
use app\modules\neuron\classes\tools\wiki\WikipediaLoader;

/**
 * Фабрика для создания менеджеров поиска статей.
 */
class ArticleSearchFactory
{
    /**
     * Создает менеджер поиска со всеми доступными поисковиками.
     *
     * @return ArticleSearchManager Менеджер поиска
     */
    public static function createFullManager(): ArticleSearchManager
    {
        $wikiLoader = new WikipediaLoader();
        return new ArticleSearchManager([
            new WikipediaArticleSearcher($wikiLoader, 'en'),
            new WikipediaArticleSearcher($wikiLoader, 'ru'),
            new RuWikiArticleSearcher(new RuWikiLoader()),
        ]);
    }

    /**
     * Создает менеджер поиска только для Wikipedia.
     *
     * @param string $language Язык Wikipedia (по умолчанию 'en')
     * @return ArticleSearchManager Менеджер поиска
     */
    public static function createWikipediaOnlyManager(string $language = 'en'): ArticleSearchManager
    {
        $wikiLoader = new WikipediaLoader();
        return new ArticleSearchManager([
            new WikipediaArticleSearcher($wikiLoader, $language),
        ]);
    }

    /**
     * Создает менеджер поиска только для RuWiki.
     *
     * @return ArticleSearchManager Менеджер поиска
     */
    public static function createRuWikiOnlyManager(): ArticleSearchManager
    {
        return new ArticleSearchManager([
            new RuWikiArticleSearcher(new RuWikiLoader()),
        ]);
    }

    /**
     * Создает менеджер поиска для мультиязычной Wikipedia.
     *
     * @param string[] $languages Массив языков (например, ['en', 'ru', 'de'])
     * @return ArticleSearchManager Менеджер поиска
     */
    public static function createMultilingualWikipediaManager(array $languages): ArticleSearchManager
    {
        $wikiLoader = new WikipediaLoader();
        $searchers = [];
        foreach ($languages as $language) {
            $searchers[] = new WikipediaArticleSearcher($wikiLoader, $language);
        }
        
        return new ArticleSearchManager($searchers);
    }

    /**
     * Создает кастомный менеджер поиска.
     *
     * @param ArticleSearcherInterface[] $searchers Массив поисковиков
     * @return ArticleSearchManager Менеджер поиска
     */
    public static function createCustomManager(array $searchers): ArticleSearchManager
    {
        return new ArticleSearchManager($searchers);
    }

    /**
     * Создает менеджер поиска с Ollama Web Search
     */
    public static function createOllamaEnhancedManager(string $apiKey = ''): ArticleSearchManager
    {
        $ollamaService = new \app\modules\neuron\services\ollama\OllamaApiService(
            'https://ollama.com',
            $apiKey
        );
        $wikiLoader = new WikipediaLoader();
        return new ArticleSearchManager([
            new WikipediaArticleSearcher($wikiLoader, 'en'),
            new WikipediaArticleSearcher($wikiLoader, 'ru'),
            new RuWikiArticleSearcher(new RuWikiLoader()),
            new OllamaArticleSearcher($ollamaService),
        ]);
    }

    /**
     * Создает менеджер поиска только с Ollama Web Search
     */
    public static function createOllamaOnlyManager(string $apiKey = ''): ArticleSearchManager
    {
        $ollamaService = new \app\modules\neuron\services\ollama\OllamaApiService(
            'https://ollama.com',
            $apiKey
        );
        
        return new ArticleSearchManager([
            new OllamaArticleSearcher($ollamaService),
        ]);
    }

    /**
     * Создает гибридный менеджер с приоритетом Ollama
     */
    public static function createHybridManager(
        array $wikipediaLanguages = ['en', 'ru'],
        bool $includeRuWiki = true,
        string $apiKey = ''
    ): ArticleSearchManager {
        $searchers = [];
        
        // Ollama добавляется первым как основной поисковик
        $ollamaService = new \app\modules\neuron\services\ollama\OllamaApiService(
            'https://ollama.com',
            $apiKey
        );
        $searchers[] = new OllamaArticleSearcher($ollamaService);

        $wikiLoader = new WikipediaLoader();
        // Затем добавляем специализированные поисковики
        foreach ($wikipediaLanguages as $language) {
            $searchers[] = new WikipediaArticleSearcher($wikiLoader, $language);
        }
        
        // Добавляем RuWiki если нужно
        if ($includeRuWiki) {
            $searchers[] = new RuWikiArticleSearcher(new RuWikiLoader());
        }
        
        return new ArticleSearchManager($searchers);
    }
}
