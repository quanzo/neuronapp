<?php

namespace app\modules\neuron\tools;

use Amp\Future;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\dto\ollama\WebSearchResultDto;

use function Amp\async;
use function Amp\Future\await;

/**
 * @deprecated
 * @see modules/neuron/tools/classes/wiki/search/ollama/OllamaArticleSearcher.php
 * @see modules/neuron/tools/classes/ollama/OllamaWebFetchLoader.php
 */
class OllamaWebSearch
{
    private $client;

    public function __construct(
        protected string $apiKey = '',
        protected string $ollamaUrl = 'https://ollama.com/api'
    ) {
        $this->client = HttpClientBuilder::buildDefault();
    }

    /**
     * Сформировать запрос
     */
    protected function makeRequest(string $query, string $part = '/web_search'): Request {
        $request = new Request($this->ollamaUrl . $part, 'POST', json_encode(['query' => $query]));
        $request->setHeader('Authorization', 'Bearer ' . $this->apiKey);
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json');
        return $request;
    }

    protected function sendRequest(string $query, string $part = '/web_search'): Future {
        return async(
            function ($query) use ($part) {
                $r = [
                    'body'        => null,
                    'query'       => $query,
                    'isSuccess'   => false,
                    'isException' => false,
                    'status'      => null,
                ];
                try {
                    $request        = $this->makeRequest($query, $part);
                    $response       = $this->client->request($request);
                    $body           = $response->getBody()->buffer();
                    $r['status']    = $response->getStatus();
                    $r['isSuccess'] = $r['status'] >= 200 && $r['status'] < 300;
                    $r['body']      = $body;
                } catch (\Throwable $e) {
                    $r['isException'] = true;
                    $r['exception'] = $e;
                }
                return $r;
            }, $query
        );
    }

    /**
     * Пакетный поиск по нескольким запросам
     */
    public function search(array $queries, int $maxResults = 5): array
    {
        $futures = [];
        foreach ($queries as $index => $query) {
            $futures[$index] = $this->sendRequest($query, '/web_search');
        }
        $results = await(
            $futures
        );
        $prepResult = [];
        foreach ($results as $i => $r) {
            if ($r['isSuccess']) {
                $prepResult[$r['query']] = [];
                $arr = json_decode(
                    $r['body'],
                    true
                );
                if (!empty($arr['results'])) {
                    foreach ($arr['results'] as $row) {
                        $prepResult[$r['query']][] = WebSearchResultDto::fromArray($row);
                    }
                }
            }
        }
        return $prepResult;
    }

    /**
     * Пакетное получение содержимого страниц
     */
    /*public function fetch(array $urls): array {
        $futures = [];
        foreach ($urls as $index => $url) {
            $futures[$index] = $this->sendRequest($url, '/web_fetch');
        }
        $results = await(
            $futures
        );
        return $results;
    }*/
}
