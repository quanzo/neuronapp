<?php

// src/app/modules/neuron/classes/search/wiki/RuWikiArticleSearcher.php

namespace app\modules\neuron\classes\search\wiki;

use Amp\Future;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\classes\loader\wiki\ContentLoaderManager;
use app\modules\neuron\classes\loader\wiki\RuWikiLoader;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\ContentLoaderInterface;

/**
 * Поисковик статей для RuWiki.
 * Использует MediaWiki Action API для поиска и загружает полный контент статей.
 */
class RuWikiArticleSearcher extends ArticleSearcherAbstract
{
    /**
     * Загрузчик для загрузки полного контента статей.
     * Используем общий интерфейс ContentLoaderInterface, чтобы позволить
     * подменять реализацию (RuWikiLoader, RuWikiFullLoader и т.п.).
     *
     * @var ContentLoaderInterface
     */
    private ContentLoaderInterface $loader;

    /**
     * Тип источника
     * @var ContentSourceType
     */
    private ContentSourceType $sourceType;

    /**
     * Конструктор поисковика RuWiki.
     *
     * @param ContentLoaderInterface $loader Загрузчик контента
     */
    public function __construct(?ContentLoaderInterface $loader = null)
    {
        parent::__construct();
        if (!$loader) {
            $this->loader = new RuWikiLoader();
        } else {
            $this->loader = $loader;
        }
        $this->sourceType = ContentSourceType::RUWIKI;
    }

    /**
     * Выполняет поиск статей в RuWiki и загружает их полное содержимое.
     *
     * @inheritDoc
     */
    public function search(string $query, int $limit = 10, int $offset = 0): Future
    {
        return \Amp\async(function () use ($query, $limit, $offset) {
            // 1. Выполняем поиск через RuWiki Action API
            $searchResults = $this->executeSearch($query, $limit, $offset);

            // 2. Извлекаем URL найденных статей
            $articleUrls = $this->extractArticleUrls($searchResults);

            // 3. Загружаем полный контент всех статей параллельно
            return $this->loadArticlesContent($articleUrls)->await();
        });
    }

    /**
     * Выполняет поиск через RuWiki Action API.
     *
     * @param string $query Поисковый запрос
     * @param int $limit Лимит результатов
     * @param int $offset Смещение
     * @return array<string,mixed> Результаты поиска
     */
    protected function executeSearch(string $query, int $limit, int $offset): array
    {
        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl();
        $apiUrl = $baseUrl . '/api.php';

        $body = $this->makeRequest('GET', $apiUrl, [
            'action' => 'query',
            'list' => 'search',
            'srsearch' => $query,
            'srlimit' => $limit,
            'sroffset' => $offset,
            'format' => 'json',
        ])->await();

        return json_decode($body, true);
    }

    /**
     * Извлекает URL статей из результатов поиска.
     *
     * @param array<string,mixed> $searchResults Результаты поиска от RuWiki API
     * @return string[] Массив URL статей
     */
    protected function extractArticleUrls(array $searchResults): array
    {
        $urls = [];

        if (empty($searchResults['query']['search'])) {
            return $urls;
        }

        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl();

        foreach ($searchResults['query']['search'] as $page) {
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
     * Загружает полное содержимое статей RuWiki.
     *
     * @param string[] $urls
     * @return Future<ArticleContentDto[]>
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
     * Создает ArticleContentDto из данных статьи RuWiki.
     * Используется, когда нужно создать DTO без загрузки полного контента.
     *
     * @param array<string,mixed> $articleData
     * @return ArticleContentDto
     */
    protected function createArticleDto(array $articleData): ArticleContentDto
    {
        $title = $articleData['title'] ?? '';
        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl();
        $url = $baseUrl . '/wiki/' . rawurlencode($title);

        // Конвертируем HTML-сниппет в чистый текст
        $snippet = $articleData['snippet'] ?? '';
        $extract = strip_tags(html_entity_decode($snippet));

        return new ArticleContentDto(
            content: $extract,
            title: $title,
            sourceUrl: $url,
            sourceType: $this->sourceType
        );
    }

    /**
     * Возвращает тип источника RuWiki.
     *
     * @return ContentSourceType
     */
    protected function getSourceType(): ContentSourceType
    {
        return $this->sourceType;
    }

    /**
     * Возвращает краткие данные о найденных статьях без загрузки полного контента.
     *
     * @param string $query Поисковый запрос
     * @param int $limit Лимит результатов
     * @param int $offset Смещение
     * @return Future<array<int, array<string, mixed>>>
     */
    public function searchBrief(string $query, int $limit = 10, int $offset = 0): Future
    {
        return \Amp\async(function () use ($query, $limit, $offset) {
            $searchResults = $this->executeSearch($query, $limit, $offset);

            if (empty($searchResults['query']['search'])) {
                return [];
            }

            $briefResults = [];
            // Получаем базовый URL из ContentSourceType
            $baseUrl = $this->sourceType->getBaseUrl();

            foreach ($searchResults['query']['search'] as $page) {
                $briefResults[] = [
                    'title' => $page['title'] ?? '',
                    'snippet' => $page['snippet'] ?? '',
                    'url' => $baseUrl . '/wiki/' . rawurlencode($page['title'] ?? ''),
                    'pageId' => $page['pageid'] ?? 0,
                    'timestamp' => $page['timestamp'] ?? '',
                    'wordCount' => $page['wordcount'] ?? 0,
                ];
            }

            return $briefResults;
        });
    }
}
