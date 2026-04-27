<?php

// src/app/modules/neuron/classes/loader/wiki/WikipediaFullLoader2.php

namespace app\modules\neuron\classes\loader\wiki;

use app\modules\neuron\helpers\JsonHelper;
use Amp\Http\Client\Request;
use app\modules\neuron\traits\UserAgentTrait;

/**
 * Загрузчик для статей Wikipedia, использующий доступ к ревизиям.
 * Наследует базовую логику WikipediaLoader и переопределяет метод загрузки.
 */
class WikipediaFullLoader2 extends WikipediaLoader
{
    use UserAgentTrait;
    
    protected string $userAgent = 'WikipediaFullLoader/1.0';
    
    /**
     * Загружает полное содержимое статьи через MediaWiki API.
     * Использует действие "query" с prop=revisions для получения содержимого.
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
        $request->setHeader('User-Agent', $this->getUserAgent());

        try {
            $response = $this->httpClient->request($request);
            $body = $response->getBody()->buffer();
            if ($body) {
                $data = JsonHelper::decodeAssociative($body);
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
