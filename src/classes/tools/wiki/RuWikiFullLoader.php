<?php
// src/app/modules/neuron/classes/tools/wiki/RuWikiFullLoader.php

namespace app\modules\neuron\classes\tools\wiki;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\traits\tools\wiki\HtmlToPlainTextConverterTrait;
use app\modules\neuron\traits\tools\wiki\CoordinateExtractorTrait;
use app\modules\neuron\traits\tools\wiki\LinkValidatorTrait;

/**
 * Загрузчик для полных статей RuWiki (ruwiki.ru).
 * Загружает полную статью и преобразует её в форматированный plain текст.
 */
class RuWikiFullLoader implements ContentLoaderInterface
{
    use HtmlToPlainTextConverterTrait, CoordinateExtractorTrait, LinkValidatorTrait;
    
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
     * Собирать ли ссылки из статьи
     * @var bool
     */
    protected bool $collectLinks;

    /**
     * Собирать ли координаты из статьи
     * @var bool
     */
    protected bool $collectCoordinates;

    /**
     * Конструктор загрузчика RuWiki.
     * 
     * @param bool $collectLinks Собирать ссылки из статьи (по умолчанию true)
     * @param bool $collectCoordinates Собирать координаты из статьи (по умолчанию true)
     */
    public function __construct(bool $collectLinks = true, bool $collectCoordinates = true)
    {
        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->sourceType = ContentSourceType::RUWIKI;
        $this->collectLinks = $collectLinks;
        $this->collectCoordinates = $collectCoordinates;
    }

    /**
     * Устанавливает, нужно ли собирать ссылки из статьи.
     * 
     * @param bool $collectLinks
     * @return self
     */
    public function setCollectLinks(bool $collectLinks): self
    {
        $this->collectLinks = $collectLinks;
        return $this;
    }

    /**
     * Устанавливает, нужно ли собирать координаты из статьи.
     * 
     * @param bool $collectCoordinates
     * @return self
     */
    public function setCollectCoordinates(bool $collectCoordinates): self
    {
        $this->collectCoordinates = $collectCoordinates;
        return $this;
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
     * Загружает полное содержимое статьи RuWiki через MediaWiki API.
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
                    "URL не поддерживается RuWikiFullLoader: {$url}"
                );
            }
            
            // Извлекаем название статьи из URL
            $title = $this->extractArticleTitle($url);
            
            if (!$title) {
                throw new \InvalidArgumentException(
                    "Не удалось извлечь название статьи RuWiki из URL: {$url}"
                );
            }
            
            // Загружаем HTML контент через MediaWiki API
            $htmlContent = $this->fetchFullArticleContent($title);
            
            // Преобразуем HTML в форматированный plain текст
            $conversionResult = $this->convertHtmlToPlainText($htmlContent);
            $plainText = $conversionResult['text'];
            
            // Собираем ссылки только если нужно
            $links = $this->collectLinks ? $conversionResult['links'] : [];
            
            // Извлекаем координаты только если нужно
            $coordinates = $this->collectCoordinates ? $this->extractCoordinates($plainText) : [];
            
            // Проверяем ссылки на доступность асинхронно (только если собираем ссылки)
            $validatedLinks = [];
            $validLinks = [];
            
            if ($this->collectLinks && !empty($links)) {
                $validatedLinks = $this->validateLinks($links, $this->httpClient);
                
                // Фильтруем только корректные ссылки (статус 'valid')
                $validLinks = array_filter($validatedLinks, function($link) {
                    return ($link['status'] ?? '') === 'valid';
                });
            }
            
            // Извлекаем заголовок статьи из API ответа
            $articleTitle = $this->extractTitleFromApiResponse($htmlContent) ?? urldecode($title);
            
            // Создаем метаданные
            $metadata = [];
            
            if ($this->collectLinks) {
                $metadata['links'] = $validLinks; // Только корректные ссылки
                $metadata['all_links'] = $validatedLinks; // Все ссылки с разными статусами
                $metadata['link_count'] = count($validatedLinks);
                $metadata['valid_links_count'] = count($validLinks);
            }
            
            if ($this->collectCoordinates) {
                $metadata['coordinates'] = $coordinates;
                $metadata['coordinates_count'] = count($coordinates);
            }
            
            // Используем enum для типа источника
            return new ArticleContentDto(
                content: $plainText,
                title: $articleTitle,
                sourceUrl: $url,
                sourceType: $this->sourceType,
                metadata: $metadata
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
            return urldecode($matches[1]);
        }

        // Извлекаем из query параметра title
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            if (isset($queryParams['title'])) {
                return urldecode($queryParams['title']);
            }
        }
        
        return null;
    }

    /**
     * Загружает полное содержимое статьи через MediaWiki API RuWiki.
     * Использует действие "parse" для получения полного HTML-контента.
     *
     * @param string $title Название статьи
     * @return string HTML содержимое статьи
     */
    protected function fetchFullArticleContent(string $title): string
    {
        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl();
        $apiUrl = $baseUrl . '/api.php?' . http_build_query([
            'action' => 'parse',
            'format' => 'json',
            'page'   => $title,
            'prop'   => 'text',
            'utf8'   => 1,
            'redirects' => 1
        ]);
        
        $request = new Request($apiUrl, 'GET');
        $request->setHeader('User-Agent', 'RuWikiFullLoader/1.0');
        
        $response = $this->httpClient->request($request);
        $body = $response->getBody()->buffer();
        $data = json_decode($body, true);
        
        if (isset($data['parse']['text']['*'])) {
            return $data['parse']['text']['*'];
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
        $data = json_decode($apiResponse, true);
        
        if (isset($data['parse']['title'])) {
            return $data['parse']['title'];
        }
        
        return null;
    }
}
