<?php

namespace app\modules\neuron\classes\dto\wiki;

/**
 * Data Transfer Object (DTO) для представления результата работы инструмента поиска.
 * Инкапсулирует список статей и отвечает за преобразование их в массив для сериализации.
 */
final class SearchToolResultDto
{
    /**
     * Массив найденных статей.
     *
     * @var ArticleContentDto[]
     */
    private array $articles;

    /**
     * Конструктор DTO результата поиска.
     *
     * @param ArticleContentDto[] $articles Массив DTO найденных статей
     */
    public function __construct(array $articles)
    {
        $this->articles = $articles;
    }

    /**
     * Возвращает массив результата для сериализации.
     * Формат совместим с текущим контрактом инструмента:
     * [
     *   'articles' => [
     *     ['title' => string, 'content' => string],
     *     ...
     *   ]
     * ]
     *
     * @return array Массив результата поиска
     */
    public function toArray(): array
    {
        $result = [
            'articles' => [],
        ];

        foreach ($this->articles as $article) {
            if (!$article instanceof ArticleContentDto) {
                continue;
            }

            $articleData = $article->toArray();

            $result['articles'][] = [
                'title'   => $articleData['title'] ?? '',
                'content' => $articleData['content'] ?? '',
            ];
        }

        return $result;
    }
}

