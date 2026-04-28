<?php

// src/app/modules/neuron/classes/search/wiki/ArticleSearcherAbstract.php

namespace app\modules\neuron\classes\search\wiki;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\ArticleSearcherInterface;
use app\modules\neuron\traits\UserAgentTrait;

/**
 * Абстрактный базовый класс для поисковиков статей.
 * Реализует общую логику HTTP-запросов и обработки результатов.
 */
abstract class ArticleSearcherAbstract implements ArticleSearcherInterface
{
    use UserAgentTrait;

    /**
     * HTTP-клиент Amp для выполнения запросов
     * @var HttpClient
     */
    protected HttpClient $httpClient;

    /**
     * Конструктор абстрактного поисковика.
     */
    public function __construct()
    {
        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->setUserAgent('ArticleSearcher/1.0');
    }

    /**
     * Выполняет асинхронный HTTP-запрос и возвращает Future с ответом.
     *
     * @param string $method HTTP-метод (GET, POST)
     * @param string $url Полный URL для запроса
     * @param array<string, scalar> $queryParams Параметры запроса (будут добавлены в URL)
     *
     * @return Future<string> Future, которое разрешится в тело ответа
     */
    protected function makeRequest(string $method, string $url, array $queryParams = []): Future
    {
        return \Amp\async(function () use ($method, $url, $queryParams) {
            if ($queryParams) {
                $url .= '?' . http_build_query($queryParams);
            }

            $request = new Request($url, $method);
            $request->setHeader('User-Agent', $this->getUserAgent());

            $response = $this->httpClient->request($request);
            return $response->getBody()->buffer();
        });
    }

    /**
     * Загружает полное содержимое статей по массиву URL.
     * Использует ContentLoaderManager для параллельной загрузки контента.
     *
     * @param string[] $urls Массив URL статей для загрузки
     * @return Future<ArticleContentDto[]> Future с массивом DTO статей
     */
    abstract protected function loadArticlesContent(array $urls): Future;

    /**
     * Создает ArticleContentDto из данных поиска.
     *
     * @param array<string,mixed> $articleData Данные статьи из API поиска
     * @return ArticleContentDto DTO статьи
     */
    abstract protected function createArticleDto(array $articleData): ArticleContentDto;

    /**
     * Возвращает тип источника для данного поисковика.
     *
     * @return ContentSourceType Тип источника (enum)
     */
    abstract protected function getSourceType(): ContentSourceType;
}
