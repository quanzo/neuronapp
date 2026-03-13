<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO результата HTTP-запроса инструмента FetchTool.
 *
 * Инкапсулирует ключевые данные ответа: конечный URL (с учётом редиректов),
 * статус-код, заголовки и тело (при необходимости обрезанное по лимиту).
 *
 * Формат сериализации (toArray):
 * [
 *     'url'        => string,          // запрошенный или финальный URL
 *     'statusCode' => int,             // HTTP-статус; 0 при сетевой ошибке
 *     'headers'    => array<string,string>, // нормализованные заголовки ответа
 *     'body'       => string,          // текст тела (может быть обрезан)
 *     'truncated'  => bool,            // было ли тело усечено по лимиту
 * ]
 */
final class HttpFetchResultDto
{
    /**
     * @param string              $url        Итоговый URL запроса
     * @param int                 $statusCode HTTP-статус-код
     * @param array<string,string> $headers   Ассоциативный массив заголовков
     * @param string              $body       Тело ответа (полное или усечённое)
     * @param bool                $truncated  Было ли тело усечено по лимиту
     */
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
        public readonly bool $truncated,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{
     *     url: string,
     *     statusCode: int,
     *     headers: array<string,string>,
     *     body: string,
     *     truncated: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'statusCode' => $this->statusCode,
            'headers' => $this->headers,
            'body' => $this->body,
            'truncated' => $this->truncated,
        ];
    }
}

