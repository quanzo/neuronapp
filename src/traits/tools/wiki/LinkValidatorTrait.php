<?php

// src/app/modules/neuron/traits/tools/wiki/LinkValidatorTrait.php

namespace app\modules\neuron\traits\tools\wiki;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use app\modules\neuron\traits\UserAgentTrait;

/**
 * Трейт для асинхронной проверки ссылок на доступность.
 */
trait LinkValidatorTrait
{
    use UserAgentTrait;

    protected string $userAgent = 'WikipediaFullLoader/1.0';

    /**
     * Таймаут проверки ссылок в миллисекундах.
     *
     * @var int
     */
    protected int $linkValidationTimeout = 1000; // 1 секунда по умолчанию

    /**
     * Асинхронно проверяет ссылки на доступность.
     *
     * @param array      $links      Массив ссылок
     * @param HttpClient $httpClient HTTP-клиент
     *
     * @return array Массив с проверенными ссылками
     */
    protected function validateLinks(array $links, HttpClient $httpClient): array
    {
        // Создаем массив промисов для асинхронных запросов
        $promises = [];

        foreach ($links as $url => $linkInfo) {
            $promises[$url] = \Amp\async(function () use ($url, $linkInfo, $httpClient) {
                try {
                    $request = new Request($url, 'GET');
                    $request->setHeader('User-Agent', $this->getUserAgent());
                    $request->setTransferTimeout($this->linkValidationTimeout);
                    $request->setInactivityTimeout($this->linkValidationTimeout);

                    $response   = $httpClient->request($request);
                    $statusCode = $response->getStatus();

                    // Проверяем, что статус 200 и нет редиректов
                    if ($statusCode === 200 && !$response->getPreviousResponse()) {
                        $linkInfo['status']      = 'valid';
                        $linkInfo['status_code'] = $statusCode;
                    } else {
                        $linkInfo['status']      = 'invalid';
                        $linkInfo['status_code'] = $statusCode;
                        $linkInfo['error']       = 'HTTP status ' . $statusCode . ' or redirect detected';
                    }
                } catch (\Throwable $e) {
                    // Если произошла ошибка при запросе
                    $linkInfo['status'] = 'error';
                    $linkInfo['error']  = $e->getMessage();
                }

                return $linkInfo;
            });
        }

        // Ожидаем выполнения всех промисов
        $results = [];
        foreach ($promises as $url => $promise) {
            $results[$url] = $promise->await();
        }

        return $results;
    }

    /**
     * Устанавливает таймаут проверки ссылок.
     *
     * @param int $timeout Таймаут в миллисекундах
     *
     * @return void
     */
    protected function setLinkValidationTimeout(int $timeout): void
    {
        $this->linkValidationTimeout = $timeout;
    }

    /**
     * Получает текущий таймаут проверки ссылок.
     *
     * @return int Таймаут в миллисекундах
     */
    protected function getLinkValidationTimeout(): int
    {
        return $this->linkValidationTimeout;
    }
}
