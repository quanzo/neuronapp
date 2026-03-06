<?php
// src/app/modules/neuron/classes/tools/wiki/RuWikiLoader.php

namespace app\modules\neuron\classes\tools\wiki;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;

/**
 * Загрузчик для статей RuWiki (ruwiki.ru).
 * Использует MediaWiki API для получения структурированного контента.
 */
class RuWikiLoader implements ContentLoaderInterface
{
    /**
     * HTTP-клиент Amp для выполнения запросов
     * @var HttpClient
     */
    protected HttpClient $httpClient;
    
    /**
     * Тип источника
     * @var ContentSourceType
     */
    private ContentSourceType $sourceType;

    /**
     * Конструктор загрузчика RuWiki.
     */
    public function __construct()
    {
        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->sourceType = ContentSourceType::RUWIKI;
    }

    /**
     * Проверяет, является ли URL ссылкой на RuWiki.
     *
     * @param string $url URL для проверки
     * @return bool True, если URL относится к RuWiki
     */
    public function canLoad(string $url): bool
    {
        $parsedUrl = parse_url($url);
        
        if (!isset($parsedUrl['host'])) {
            return false;
        }
        
        $host = strtolower($parsedUrl['host']);
        
        // Проверяем домены RuWiki
        return str_contains($host, 'ruwiki.ru') ||
               str_contains($host, 'ruwiki.org') ||
               preg_match('/\.ruwiki\.(ru|org)$/i', $host) === 1;
    }

    /**
     * Загружает содержимое статьи RuWiki через MediaWiki API.
     * 
     * @throws \InvalidArgumentException Если URL не поддерживается этим загрузчиком
     *
     * @param string $url URL статьи RuWiki
     * @return Future<ArticleContentDto> Future с содержимым статьи
     */
    public function load(string $url): Future
    {
        return \Amp\async(function () use ($url) {
            // Проверяем, что URL может быть обработан этим загрузчиком
            if (!$this->canLoad($url)) {
                throw new \InvalidArgumentException(
                    "URL не поддерживается RuWikiLoader: {$url}"
                );
            }
            
            // Извлекаем название статьи из URL
            $title = $this->extractArticleTitle($url);
            
            if (!$title) {
                throw new \InvalidArgumentException(
                    "Не удалось извлечь название статьи RuWiki из URL: {$url}"
                );
            }
            
            // Загружаем контент через MediaWiki API
            $content = $this->fetchArticleContent($title);
            
            // Извлекаем заголовок статьи из контента
            $articleTitle = $this->extractTitleFromApiResponse($content) ?? urldecode($title);
            
            // Используем enum для типа источника
            return new ArticleContentDto(
                content   : $content,
                title     : $articleTitle,
                sourceUrl : $url,
                sourceType: $this->sourceType
            );
        });
    }

    /**
     * Извлекает название статьи из URL RuWiki.
     *
     * @param string $url URL статьи RuWiki
     * @return string|null Название статьи или null
     */
    protected function extractArticleTitle(string $url): ?string
    {
        $parsedUrl = parse_url($url);

        if (!isset($parsedUrl['path'])) {
            return null;
        }

        // Извлекаем из пути /wiki/Название_статьи
        if (preg_match('#/wiki/([^/?&]+)#', $parsedUrl['path'], $matches)) {
            return urldecode($matches[1]); // Добавлен urldecode
        }

        // Извлекаем из query параметра title
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            if (isset($queryParams['title'])) {
                return urldecode($queryParams['title']); // Добавлен urldecode
            }
        }
        return null;
    }

    /**
     * Загружает содержимое статьи через MediaWiki API RuWiki.
     *
     * @param string $title Название статьи
     * @return string HTML содержимое статьи
     */
    protected function fetchArticleContent(string $title): string
    {
        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl();
        $apiUrl = $baseUrl . '/api.php?' . http_build_query([
            'action'          => 'query',
            'format'          => 'json',
            'prop'            => 'extracts',
            'exlimit'         => '1',
            'explaintext'     => 'true',
            'exsectionformat' => 'plain',
            'exintro'         => 'false',
            'titles'          => $title
        ]);
        
        $request = new Request($apiUrl, 'GET');
        $request->setHeader('User-Agent', 'RuWikiLoader/1.0');
        
        try {
            $response = $this->httpClient->request($request);
            $body = $response->getBody()->buffer();
            $data = json_decode($body, true);
            if (isset($data['query']['pages'])) {
                $page = reset($data['query']['pages']);
                return $page['extract'] ?? '';
            }
        } catch (\Throwable $e) {}
        return '';
    }

    /**
     * Извлекает заголовок статьи из ответа API.
     *
     * @param string $apiResponse JSON ответ от MediaWiki API
     * @return string|null Заголовок статьи или null
     */
    protected function extractTitleFromApiResponse(string $apiResponse): ?string
    {
        $data = json_decode($apiResponse, true);
        
        if (isset($data['query']['pages'])) {
            $page = reset($data['query']['pages']);
            return $page['title'] ?? null;
        }
        
        return null;
    }
}
