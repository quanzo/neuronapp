<?php

namespace app\modules\neuron\classes\dto\ollama;

/**
 * Одна запись в результатах поиска
 */
class WebSearchResultDto
{
    public function __construct(
        /** @var string заголовок */
        public readonly string $title,
        /** @var string url страницы с данными */
        public readonly string $url,
        /** @var string контент */
        public readonly string $content,
    ) {
    }

    /**
     * Создает DTO из массива данных
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title  : $data['title'] ?? '',
            url    : $data['url'] ?? '',
            content: $data['content'] ?? null,
        );
    }
}
