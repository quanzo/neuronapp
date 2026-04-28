<?php

// src/app/modules/neuron/classes/loader/wiki/WikipediaLoader.php

namespace app\modules\neuron\classes\loader\wiki;

use app\modules\neuron\helpers\JsonHelper;
use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\ContentLoaderInterface;
use app\modules\neuron\traits\UserAgentTrait;

/**
 * Загрузчик для статей Wikipedia.
 * Использует MediaWiki API для получения структурированного контента.
 * Поддерживает все языковые домены Wikipedia (en.wikipedia.org, ru.wikipedia.org и т.д.)
 */
class WikipediaLoader implements ContentLoaderInterface
{
    use UserAgentTrait;

    /**
     * HTTP-клиент Amp для выполнения запросов
     * @var HttpClient
     */
    protected HttpClient $httpClient;

    /**
     * Тип источника
     * @var ContentSourceType
     */
    protected ContentSourceType $sourceType;

    /**
     * Конструктор загрузчика Wikipedia.
     */
    public function __construct()
    {
        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->sourceType = ContentSourceType::WIKIPEDIA;
        $this->setUserAgent('WikipediaLoader/1.0');
    }

    /**
     * Проверяет, является ли URL ссылкой на Wikipedia.
     * Поддерживает все языковые поддомены и все домены Wikimedia Foundation.
     *
     * @param string $url URL для проверки
     * @return bool True, если URL относится к Wikipedia
     */
    public function canLoad(string $url): bool
    {
        $parsedUrl = parse_url($url);

        if (!isset($parsedUrl['host'])) {
            return false;
        }

        $host = strtolower($parsedUrl['host']);

        // Проверяем все домены Wikipedia и Wikimedia
        // Примеры: en.wikipedia.org, ru.wikipedia.org, es.wikipedia.org
        // Также поддерживаем mobile версии и другие поддомены
        return preg_match('/\.wikipedia\.org$/i', $host) === 1 ||
               preg_match('/\.wikimedia\.org$/i', $host) === 1 ||
               str_contains($host, 'wikipedia.org') ||
               str_contains($host, 'wikimedia.org');
    }

    /**
     * Загружает содержимое статьи Wikipedia через MediaWiki API.
     * Извлекает заголовок статьи из URL и запрашивает контент через API.
     *
     * @throws \InvalidArgumentException Если URL не поддерживается этим загрузчиком
     *
     * @param string $url URL статьи Wikipedia
     * @return Future<ArticleContentDto> Future с содержимым статьи
     */
    public function load(string $url): Future
    {
        return \Amp\async(function () use ($url) {
            // Проверяем, что URL может быть обработан этим загрузчиком
            if (!$this->canLoad($url)) {
                throw new \InvalidArgumentException(
                    "URL не поддерживается WikipediaLoader: {$url}"
                );
            }

            // Извлекаем название статьи из URL
            $title = $this->extractArticleTitle($url);

            if (!$title) {
                throw new \InvalidArgumentException(
                    "Не удалось извлечь название статьи Wikipedia из URL: {$url}"
                );
            }

            // Определяем язык Wikipedia из URL
            $language = ContentSourceType::extractWikipediaLanguage($url) ?? 'en';

            // Загружаем контент через MediaWiki API
            $content = $this->fetchArticleContent($title, $language);

            // Извлекаем заголовок статьи из контента или используем название
            $articleTitle = $this->extractTitleFromApiResponse($content) ?? urldecode($title);

            // Используем enum для типа источника
            return new ArticleContentDto(
                content: $content,
                title: $articleTitle,
                sourceUrl: $url,
                sourceType: $this->sourceType
            );
        });
    }

    /**
     * Извлекает название статьи из URL Wikipedia.
     * Обрабатывает различные форматы URL:
     * - /wiki/Article_Name
     * - /wiki/Article_Name?param=value
     * - /w/index.php?title=Article_Name
     *
     * @param string $url URL статьи Wikipedia
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
            // ВАЖНО: Декодируем URL-encoded строку перед передачей в API
            return urldecode($matches[1]);
        }

        // Извлекаем из query параметра title (для /w/index.php)
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            if (isset($queryParams['title'])) {
                return urldecode($queryParams['title']);
            }
        }

        return null;
    }

    /**
     * Загружает содержимое статьи через MediaWiki API.
     * Использует модуль "extracts" для получения HTML-контента.
     *
     * @param string $title Название статьи
     * @param string $language Язык Wikipedia
     * @return string HTML содержимое статьи
     */
    protected function fetchArticleContent(string $title, string $language): string
    {
        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl($language);
        $apiUrl = $baseUrl . '/w/api.php?' . http_build_query([
            'action'          => 'query',
            'format'          => 'json',
            'prop'            => 'extracts',
            'exlimit'         => '1',
            'explaintext'     => 'true',
            'exsectionformat' => 'plain',
            'exintro'         => 'false',      // Получаем полную статью, не только введение
            'titles'          => $title
        ]);

        $request = new Request($apiUrl, 'GET');
        $request->setHeader('User-Agent', $this->getUserAgent());

        try {
            $response = $this->httpClient->request($request);
            $body     = $response->getBody()->buffer();
            $data     = JsonHelper::decodeAssociative($body);
            if (isset($data['query']['pages'])) {
                $page = reset($data['query']['pages']);
                return $page['extract'] ?? '';
            }
        } catch (\Throwable $e) {
        }

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
        $data = JsonHelper::decodeAssociative($apiResponse);

        if (isset($data['query']['pages'])) {
            $page = reset($data['query']['pages']);
            return $page['title'] ?? null;
        }

        return null;
    }
}
