<?php

// src/app/modules/neuron/classes/search/wiki/ArticleSearchManager.php

namespace app\modules\neuron\classes\search\wiki;

use Amp\Future;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\ArticleSearcherInterface;

/**
 * Менеджер для поиска статей в нескольких источниках одновременно.
 * Позволяет выполнять поиск в Wikipedia, RuWiki и других источниках параллельно.
 */
class ArticleSearchManager
{
    /**
     * Массив поисковиков по источникам [тип_источника => поисковик]
     * @var ArticleSearcherInterface[]
     */
    private array $searchers = [];

    /**
     * Конструктор менеджера поиска.
     *
     * @param ArticleSearcherInterface[] $searchers Массив поисковиков
     */
    public function __construct(array $searchers = [])
    {
        $this->setSearchers($searchers);
    }

    /**
     * Устанавливает поисковики.
     *
     * @param ArticleSearcherInterface[] $searchers Массив поисковиков
     * @return self
     */
    public function setSearchers(array $searchers): self
    {
        $this->searchers = [];

        foreach ($searchers as $searcher) {
            if ($searcher instanceof ArticleSearcherInterface) {
                $this->addSearcher($searcher);
            }
        }

        return $this;
    }

    /**
     * Добавляет поисковик.
     *
     * @param ArticleSearcherInterface $searcher Поисковик для добавления
     * @return self
     */
    public function addSearcher(ArticleSearcherInterface $searcher): self
    {
        $this->searchers[] = $searcher;
        return $this;
    }

    /**
     * Выполняет поиск во всех источниках одновременно.
     * Возвращает объединенные результаты из всех источников.
     *
     * @param string $query Поисковый запрос
     * @param int $limitPerSource Лимит результатов на каждый источник
     * @param int $offset Смещение для пагинации
     * @return Future<ArticleContentDto[]> Future с объединенными результатами
     */
    public function searchAll(string $query, int $limitPerSource = 5, int $offset = 0): Future
    {
        return \Amp\async(function () use ($query, $limitPerSource, $offset) {
            // Запускаем поиск во всех источниках параллельно
            $futures = [];
            foreach ($this->searchers as $searcher) {
                $futures[] = $searcher->search($query, $limitPerSource, $offset);
            }

            // Ожидаем завершения всех поисков
            $resultsBySource = Future\await($futures);

            // Объединяем результаты из всех источников
            $allResults = [];
            foreach ($resultsBySource as $sourceResults) {
                if (is_array($sourceResults)) {
                    $allResults = array_merge($allResults, $sourceResults);
                }
            }

            return $allResults;
        });
    }

    /**
     * Выполняет поиск только в указанных типах источников.
     *
     * @param string $query Поисковый запрос
     * @param ContentSourceType[] $sourceTypes Типы источников для поиска
     * @param int $limitPerSource Лимит результатов на каждый источник
     * @return Future<ArticleContentDto[]> Future с результатами
     */
    public function searchInSources(string $query, array $sourceTypes, int $limitPerSource = 5): Future
    {
        return \Amp\async(function () use ($query, $sourceTypes, $limitPerSource) {
            $futures = [];

            // Фильтруем поисковики по типам источников
            foreach ($this->searchers as $searcher) {
                if (
                    $searcher instanceof WikipediaArticleSearcher &&
                    in_array(ContentSourceType::WIKIPEDIA, $sourceTypes, true)
                ) {
                    $futures[] = $searcher->search($query, $limitPerSource);
                } elseif (
                    $searcher instanceof RuWikiArticleSearcher &&
                    in_array(ContentSourceType::RUWIKI, $sourceTypes, true)
                ) {
                    $futures[] = $searcher->search($query, $limitPerSource);
                }
            }

            if (empty($futures)) {
                return [];
            }

            $resultsBySource = Future\await($futures);

            $allResults = [];
            foreach ($resultsBySource as $sourceResults) {
                if (is_array($sourceResults)) {
                    $allResults = array_merge($allResults, $sourceResults);
                }
            }

            return $allResults;
        });
    }

    /**
     * Возвращает краткие результаты поиска без загрузки полного контента.
     * Полезно для быстрого отображения результатов.
     *
     * @param string $query Поисковый запрос
     * @param int $limitPerSource Лимит результатов на каждый источник
     * @return Future<array<int, array<string, mixed>>> Future с краткими результатами
     */
    public function searchBriefAll(string $query, int $limitPerSource = 5): Future
    {
        return \Amp\async(function () use ($query, $limitPerSource) {
            $futures = [];

            foreach ($this->searchers as $searcher) {
                if ($searcher instanceof WikipediaArticleSearcher) {
                    $futures[] = $searcher->searchBrief($query, $limitPerSource);
                } elseif ($searcher instanceof RuWikiArticleSearcher) {
                    $futures[] = $searcher->searchBrief($query, $limitPerSource);
                }
            }

            if (empty($futures)) {
                return [];
            }

            $resultsBySource = Future\await($futures);

            $allResults = [];
            foreach ($resultsBySource as $sourceResults) {
                if (is_array($sourceResults)) {
                    $allResults = array_merge($allResults, $sourceResults);
                }
            }

            return $allResults;
        });
    }

    /**
     * Возвращает количество поисковиков.
     *
     * @return int Количество поисковиков
     */
    public function getSearchersCount(): int
    {
        return count($this->searchers);
    }
}
