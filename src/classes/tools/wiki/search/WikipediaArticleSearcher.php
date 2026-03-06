<?php
// src/app/modules/neuron/classes/tools/wiki/search/WikipediaArticleSearcher.php

namespace app\modules\neuron\classes\tools\wiki\search;

use Amp\Future;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\classes\tools\wiki\ContentLoaderInterface;
use app\modules\neuron\classes\tools\wiki\ContentLoaderManager;
use app\modules\neuron\classes\tools\wiki\WikipediaLoader;

/**
 * Поисковик статей для Wikipedia.
 * Использует Wikipedia REST API для поиска и загружает полный контент статей.
 */
class WikipediaArticleSearcher extends ArticleSearcherAbstract
{
    /**
     * Язык Wikipedia (по умолчанию английский)
     * @var string
     */
    private string $language;
    
    /**
     * Загрузчик для загрузки полного контента статей
     * @var WikipediaLoader
     */
    private WikipediaLoader $loader;
    
    /**
     * Тип источника
     * @var ContentSourceType
     */
    private ContentSourceType $sourceType;

    /**
     * Конструктор поисковика Wikipedia.
     *
     * @param string $language Язык Wikipedia (например, 'en', 'ru', 'de')
     */
    public function __construct(
        ContentLoaderInterface $loader,
        string $language = 'en'
    ) {
        parent::__construct();
        $this->language = $language;
        $this->loader = new WikipediaLoader();
        $this->sourceType = ContentSourceType::WIKIPEDIA;
    }

    /**
     * @inheritDoc
     * Выполняет поиск статей в Wikipedia и загружает их полное содержимое.
     */
    public function search(string $query, int $limit = 10, int $offset = 0): Future
    {
        return \Amp\async(function () use ($query, $limit, $offset) {
            // 1. Выполняем поиск через Wikipedia REST API
            $searchResults = $this->executeSearch($query, $limit, $offset);
            
            // 2. Извлекаем URL найденных статей
            $articleUrls = $this->extractArticleUrls($searchResults);
            
            // 3. Загружаем полный контент всех статей параллельно
            return $this->loadArticlesContent($articleUrls)->await();
        });
    }

    /**
     * Выполняет поиск через Wikipedia REST API.
     *
     * @param string $query Поисковый запрос
     * @param int $limit Лимит результатов
     * @param int $offset Смещение
     * @return array Результаты поиска
     */
    protected function executeSearch(string $query, int $limit, int $offset): array
    {
        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl($this->language);
        $apiUrl = $baseUrl . '/w/rest.php/v1/search/page';
        
        $body = $this->makeRequest('GET', $apiUrl, [
            'q' => $query,
            'limit' => $limit,
        ])->await();
        
        return json_decode($body, true);
    }

    /**
     * Извлекает URL статей из результатов поиска.
     *
     * @param array $searchResults Результаты поиска от Wikipedia API
     * @return string[] Массив URL статей
     */
    protected function extractArticleUrls(array $searchResults): array
    {
        $urls = [];
        
        if (empty($searchResults['pages'])) {
            return $urls;
        }
        
        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl($this->language);
        
        foreach ($searchResults['pages'] as $page) {
            $title = $page['title'] ?? '';
            if ($title) {
                // Формируем полный URL статьи
                $url = $baseUrl . '/wiki/' . rawurlencode($title);
                $urls[] = $url;
            }
        }
        
        return $urls;
    }

    /**
     * @inheritDoc
     * Загружает полное содержимое статей Wikipedia.
     */
    protected function loadArticlesContent(array $urls): Future
    {
        return \Amp\async(function () use ($urls) {
            // Используем менеджер загрузчиков для параллельной загрузки
            $manager = new ContentLoaderManager([$this->loader]);
            $results = $manager->loadMultiple($urls)->await();
            
            // Фильтруем только успешные результаты
            $articles = [];
            foreach ($results as $content) {
                if ($content instanceof ArticleContentDto) {
                    $articles[] = $content;
                }
            }
            
            return $articles;
        });
    }

    /**
     * @inheritDoc
     * Создает ArticleContentDto из данных статьи Wikipedia.
     * Используется, когда нужно создать DTO без загрузки полного контента.
     */
    protected function createArticleDto(array $articleData): ArticleContentDto
    {
        $title = $articleData['title'] ?? '';
        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl($this->language);
        $url = $baseUrl . '/wiki/' . rawurlencode($title);
        $extract = $articleData['excerpt'] ?? '';
        
        return new ArticleContentDto(
            content: $extract,
            title: $title,
            sourceUrl: $url,
            sourceType: $this->sourceType
        );
    }

    /**
     * @inheritDoc
     * Возвращает тип источника Wikipedia.
     */
    protected function getSourceType(): ContentSourceType
    {
        return $this->sourceType;
    }

    /**
     * Возвращает язык поисковика.
     *
     * @return string Язык Wikipedia
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Возвращает краткие данные о найденных статьях без загрузки полного контента.
     * Полезно для отображения результатов поиска без задержки.
     *
     * @param string $query Поисковый запрос
     * @param int $limit Лимит результатов
     * @param int $offset Смещение
     * @return Future<array> Future с массивом кратких данных статей
     */
    public function searchBrief(string $query, int $limit = 10, int $offset = 0): Future
    {
        return \Amp\async(function () use ($query, $limit, $offset) {
            $searchResults = $this->executeSearch($query, $limit, $offset);
            
            if (empty($searchResults['pages'])) {
                return [];
            }
            
            $briefResults = [];
            // Получаем базовый URL из ContentSourceType
            $baseUrl = $this->sourceType->getBaseUrl($this->language);
            
            foreach ($searchResults['pages'] as $page) {
                $briefResults[] = [
                    'title' => $page['title'] ?? '',
                    'extract' => $page['excerpt'] ?? '',
                    'url' => $baseUrl . '/wiki/' . rawurlencode($page['title'] ?? ''),
                    'pageId' => $page['id'] ?? 0,
                    'thumbnail' => $page['thumbnail']['url'] ?? null,
                    'description' => $page['description'] ?? '',
                ];
            }
            
            return $briefResults;
        });
    }
}
