<?php

// src/app/modules/neuron/classes/loader/wiki/WikipediaFullLoader.php

namespace app\modules\neuron\classes\loader\wiki;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\ContentLoaderInterface;
use app\modules\neuron\traits\tools\wiki\HtmlToPlainTextConverterTrait;
use app\modules\neuron\traits\tools\wiki\CoordinateExtractorTrait;
use app\modules\neuron\traits\tools\wiki\LinkValidatorTrait;

/**
 * Загрузчик для полных статей Wikipedia.
 * Загружает полную статью и преобразует её в форматированный plain текст.
 */
class WikipediaFullLoader implements ContentLoaderInterface
{
    use HtmlToPlainTextConverterTrait;
    use CoordinateExtractorTrait;
    use LinkValidatorTrait;

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
     * Конструктор загрузчика Wikipedia.
     *
     * @param bool $collectLinks Собирать ссылки из статьи (по умолчанию true)
     * @param bool $collectCoordinates Собирать координаты из статьи (по умолчанию true)
     */
    public function __construct(bool $collectLinks = true, bool $collectCoordinates = true)
    {
        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->sourceType = ContentSourceType::WIKIPEDIA;
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
        return preg_match('/\.wikipedia\.org$/i', $host) === 1 ||
               preg_match('/\.wikimedia\.org$/i', $host) === 1 ||
               str_contains($host, 'wikipedia.org') ||
               str_contains($host, 'wikimedia.org');
    }

    /**
     * Загружает полное содержимое статьи Wikipedia через MediaWiki API.
     * Извлекает заголовок статьи из URL и запрашивает полный контент.
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
                    "URL не поддерживается WikipediaFullLoader: {$url}"
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

            // Загружаем HTML контент через MediaWiki API
            $htmlContent = $this->fetchFullArticleContent($title, $language);

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
                $validLinks = array_filter($validatedLinks, static function ($link) {
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
     * Загружает полное содержимое статьи через MediaWiki API.
     * Использует действие "parse" для получения полного HTML-контента.
     *
     * @param string $title Название статьи
     * @param string $language Язык Wikipedia
     * @return string HTML содержимое статьи
     */
    protected function fetchFullArticleContent(string $title, string $language): string
    {
        // Получаем базовый URL из ContentSourceType
        $baseUrl = $this->sourceType->getBaseUrl($language);
        $apiUrl = $baseUrl . '/w/api.php?' . http_build_query([
            'action' => 'parse',
            'format' => 'json',
            'page'   => $title,
            'prop'   => 'text',
            'utf8'   => 1,
            'redirects' => 1
        ]);

        $request = new Request($apiUrl, 'GET');
        $request->setHeader('User-Agent', 'WikipediaFullLoader/1.0');

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
