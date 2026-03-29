<?php

// src/app/modules/neuron/classes/search/ollama/OllamaWebSearch.php

namespace app\modules\neuron\classes\search\ollama;

use app\modules\neuron\helpers\JsonHelper;
use Amp\Future;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\dto\ollama\WebSearchResultDto;

use function Amp\async;
use function Amp\Future\await;

/**
 * @deprecated
 * @see app\modules\neuron\classes\search\ollama\OllamaArticleSearcher
 * @see app\modules\neuron\classes\loader\ollama\OllamaWebFetchLoader
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
     * Сформировать запрос.
     */
    protected function makeRequest(string $query, string $part = '/web_search'): Request
    {
        $request = new Request($this->ollamaUrl . $part, 'POST', JsonHelper::encodeThrow(['query' => $query]));
        $request->setHeader('Authorization', 'Bearer ' . $this->apiKey);
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json');
        return $request;
    }

    protected function sendRequest(string $query, string $part = '/web_search'): Future
    {
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
            },
            $query
        );
    }

    /**
     * Пакетный поиск по нескольким запросам.
     *
     * @param string[] $queries
     * @param int $maxResults
     * @return array<string, WebSearchResultDto[]>
     */
    public function search(array $queries, int $maxResults = 5): array
    {
        $futures = [];
        foreach ($queries as $index => $query) {
            $futures[$index] = $this->sendRequest($query, '/web_search');
        }
        $results = await($futures);
        $prepResult = [];
        foreach ($results as $r) {
            if ($r['isSuccess']) {
                $prepResult[$r['query']] = [];
                $arr = JsonHelper::decodeAssociative($r['body']);
                if (!empty($arr['results'])) {
                    foreach ($arr['results'] as $row) {
                        $prepResult[$r['query']][] = WebSearchResultDto::fromArray($row);
                    }
                }
            }
        }
        return $prepResult;
    }
}
