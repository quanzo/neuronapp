<?php

// src/app/modules/neuron/classes/search/ollama/OllamaArticleSearcher.php

namespace app\modules\neuron\classes\search\ollama;

use Amp\Future;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\classes\loader\ollama\OllamaWebFetchLoader;
use app\modules\neuron\classes\loader\wiki\ContentLoaderManager;
use app\modules\neuron\classes\search\wiki\ArticleSearcherAbstract;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\services\ollama\OllamaApiService;

/**
 * Поисковик статей через Ollama Web Search API.
 * Использует Ollama для поиска по вебу и интеллектуального извлечения контента.
 *
 * Документация: https://docs.ollama.com/capabilities/web-search
 */
class OllamaArticleSearcher extends ArticleSearcherAbstract
{
    private OllamaApiService $ollamaService;
    private ContentLoaderManager $contentLoaderManager;
    private ContentSourceType $sourceType;

    public function __construct(
        ?OllamaApiService $ollamaService = null,
        ?ContentLoaderManager $contentLoaderManager = null
    ) {
        parent::__construct();

        $this->ollamaService = $ollamaService ?? new OllamaApiService();
        $this->contentLoaderManager = $contentLoaderManager ?? new ContentLoaderManager([
            new OllamaWebFetchLoader($ollamaService, true), // С fallback
        ]);
        $this->sourceType = ContentSourceType::GENERIC;
    }

    /**
     * Выполняет поиск через Ollama Web Search и загружает контент.
     *
     * @inheritDoc
     */
    public function search(string $query, int $limit = 10, int $offset = 0): Future
    {
        return \Amp\async(function () use ($query, $limit) {
            // 1. Выполняем поиск через Ollama Web Search
            $searchResults = $this->ollamaService->webSearch($query)->await();

            // 2. Ограничиваем количество результатов
            if ($limit > 0 && count($searchResults) > $limit) {
                $searchResults = array_slice($searchResults, 0, $limit);
            }

            // 3. Создаем DTO из результатов поиска (в них уже есть content)
            $articles = [];
            foreach ($searchResults as $result) {
                $articles[] = $this->createArticleDto($result);
            }

            return $articles;
        });
    }

    /**
     * Загружает контент статей через Ollama Web Fetch.
     *
     * @param string[] $urls
     * @return Future<array<string, ArticleContentDto>>
     */
    protected function loadArticlesContent(array $urls): Future
    {
        return $this->contentLoaderManager->loadMultiple($urls);
    }

    /**
     * Создает ArticleContentDto из данных Ollama поиска.
     *
     * @param array<string,mixed> $articleData
     * @return ArticleContentDto
     */
    protected function createArticleDto(array $articleData): ArticleContentDto
    {
        return new ArticleContentDto(
            content: $articleData['content'] ?? '',
            title: $articleData['title'] ?? '',
            sourceUrl: $articleData['url'] ?? '',
            sourceType: $this->sourceType,
            metadata: [
                'search_source' => 'ollama_web_search',
            ]
        );
    }

    /**
     * Возвращает тип источника.
     *
     * @return ContentSourceType
     */
    protected function getSourceType(): ContentSourceType
    {
        return $this->sourceType;
    }

    /**
     * Возвращает краткие результаты поиска.
     * В случае Ollama это те же результаты, что и в search, так как content уже есть.
     *
     * @inheritDoc
     */
    public function searchBrief(string $query, int $limit = 10, int $offset = 0): Future
    {
        return \Amp\async(function () use ($query, $limit) {
            $searchResults = $this->ollamaService->webSearch($query)->await();

            // Ограничиваем количество результатов
            if ($limit > 0 && count($searchResults) > $limit) {
                $searchResults = array_slice($searchResults, 0, $limit);
            }

            $briefResults = [];
            foreach ($searchResults as $result) {
                $briefResults[] = [
                    'title'         => $result['title'] ?? '',
                    'snippet'       => $this->truncateContent($result['content'] ?? '', 200),
                    'content'       => $result['content'] ?? '',
                    'url'           => $result['url'] ?? '',
                    'search_engine' => 'ollama_web_search',
                ];
            }

            return $briefResults;
        });
    }

    /**
     * Обрезает контент до указанной длины.
     *
     * @param string $content
     * @param int $length
     * @return string
     */
    private function truncateContent(string $content, int $length): string
    {
        if (mb_strlen($content) <= $length) {
            return $content;
        }

        return mb_substr($content, 0, $length) . '...';
    }
}
