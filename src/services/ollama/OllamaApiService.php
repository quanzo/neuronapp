<?php

namespace app\modules\neuron\services\ollama;

use app\modules\neuron\helpers\JsonHelper;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Future;
use app\modules\neuron\traits\UserAgentTrait;

/**
 * Сервис для работы с Ollama Web Search API
 * Документация: https://docs.ollama.com/capabilities/web-search
 */
class OllamaApiService
{
    use UserAgentTrait;

    private HttpClient $httpClient;
    private string $baseUrl;
    private string $apiKey;

    protected string $userAgent = 'Neuron-Ollama-Integration/1.0';

    public function __construct(
        string $baseUrl = 'https://ollama.com',
        string $apiKey = ''
    ) {
        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Выполняет поиск через Ollama Web Search API
     * Endpoint: /api/web_search
     *
     * @param string $query Поисковый запрос
     * @return Future<array> Future с результатами поиска
     */
    public function webSearch(string $query): Future
    {
        return \Amp\async(function () use ($query) {
            $url = $this->baseUrl . '/api/web_search';

            $request = new Request($url, 'POST');
            $request->setHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => $this->getUserAgent(),
            ]);

            if (!empty($this->apiKey)) {
                $request->setHeader('Authorization', 'Bearer ' . $this->apiKey);
            }

            $body = JsonHelper::encodeThrow([
                'query' => $query,
            ]);

            $request->setBody($body);

            $response = $this->httpClient->request($request);
            $responseBody = $response->getBody()->buffer();

            if ($response->getStatus() !== 200) {
                throw new \RuntimeException(
                    "Ollama API error: HTTP {$response->getStatus()}. Response: {$responseBody}"
                );
            }

            $data = JsonHelper::decodeAssociative($responseBody);

            if (!isset($data['results']) || !is_array($data['results'])) {
                throw new \RuntimeException(
                    "Invalid response format from Ollama API: " . $responseBody
                );
            }

            return $data['results'];
        });
    }

    /**
     * Извлекает контент веб-страницы через Ollama Web Fetch API
     * Endpoint: /api/web_fetch
     *
     * @param string $url URL страницы
     * @return Future<array> Future с извлеченным контентом
     */
    public function webFetch(string $url): Future
    {
        return \Amp\async(function () use ($url) {
            $apiUrl = $this->baseUrl . '/api/web_fetch';

            $request = new Request($apiUrl, 'POST');
            $request->setHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => $this->getUserAgent(),
            ]);

            if (!empty($this->apiKey)) {
                $request->setHeader('Authorization', 'Bearer ' . $this->apiKey);
            }

            $body = JsonHelper::encodeThrow([
                'url' => $url,
            ]);

            $request->setBody($body);

            $response = $this->httpClient->request($request);
            $responseBody = $response->getBody()->buffer();

            if ($response->getStatus() !== 200) {
                throw new \RuntimeException(
                    "Ollama Fetch API error: HTTP {$response->getStatus()}. Response: {$responseBody}"
                );
            }

            $data = JsonHelper::decodeAssociative($responseBody);

            if (!isset($data['content'])) {
                throw new \RuntimeException(
                    "Invalid response format from Ollama Fetch API: " . $responseBody
                );
            }

            return [
                'content' => $data['content'],
                'title' => $data['title'] ?? '',
                'links' => $data['links'] ?? [],
            ];
        });
    }
}
