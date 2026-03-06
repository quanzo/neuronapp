<?php
// src/app/modules/neuron/classes/tools/wiki/WikipediaLoader.php

namespace app\modules\neuron\classes\tools\wiki;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;

/**
 * Загрузчик для статей Wikipedia.
 * Использует MediaWiki API для получения структурированного контента.
 * Поддерживает все языковые домены Wikipedia (en.wikipedia.org, ru.wikipedia.org и т.д.)
 */
class WikipediaFullLoader2 extends WikipediaLoader
{
    /**
     * Загружает полное содержимое статьи через MediaWiki API.
     * Использует действие "parse" для получения полного HTML-контента.
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
            'action'    => 'query',
            'format'    => 'json',
            'titles'    => $title,
            'prop'      => 'revisions',
            'rvprop'    => 'content',
            'rvslots'   => 'main',
            'utf8'      => 1,
            'redirects' => 1
        ]);
        
        $request = new Request($apiUrl, 'GET');
        $request->setHeader('User-Agent', 'WikipediaFullLoader/1.0');

        try {
            $response = $this->httpClient->request($request);
            $body = $response->getBody()->buffer();
            if ($body) {
                $data = json_decode($body, true);
            } else {
                $data = [];
            }
            if (isset($data['revisions'][0]['slots']['main']['content'])) {
                return $data['revisions'][0]['slots']['main']['content'];
            }
        } catch (\Exception $e) {
            
        }
        return '';
    }
}
