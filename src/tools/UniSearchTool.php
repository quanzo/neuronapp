<?php

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\classes\dto\wiki\SearchToolResultDto;
use app\modules\neuron\classes\search\wiki\ArticleSearchManager;
use app\modules\neuron\interfaces\ArticleSearcherInterface;
use app\modules\neuron\classes\search\wiki\WikipediaArticleSearcher;
use app\modules\neuron\classes\loader\wiki\WikipediaFullLoader2;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Инструмент для поиска и загрузки содержимого статей из различных источников.
 * Использует набор поисковиков ({@see ArticleSearcherInterface}) и возвращает результат в виде JSON.
 */
class UniSearchTool extends Tool
{
    /**
     * Массив поисковиков статей.
     *
     * @var ArticleSearcherInterface[]
     */
    protected array $searchers = [];

    /**
     * Максимальное кол-во вызова инструмента в сессию
     */
    protected ?int $maxTries = 50;

    /**
     * Лимит результатов на один источник по умолчанию.
     */
    protected int $defaultLimitPerSource = 5;

    /**
     * Конструктор
     *
     * @param string $name - имя инструмента
     * @param string $description - описание инструмента
     * @param ArticleSearcherInterface[] $searchers - массив поисковиков статей в разных сервисах
     */
    public function __construct(
        string $name = 'wiki_search',
        string $description = 'Выполняет поиск в терминов, определений и другой различной информации. Поддерживает поиск и загрузку страниц.',
        array $searchers = []
    ) {
        parent::__construct(
            name: $name,
            description: $description,
        );
        $this->searchers = $searchers;
        if (empty($this->searchers)) {
            // конфиг по умолчанию
            $wikiLoader = new WikipediaFullLoader2();
            $this->searchers = [
                new WikipediaArticleSearcher('ru'),
                new WikipediaArticleSearcher('en'),
            ];
        }
    }

    /**
     * Свойства инструмента, описывающие входные параметры.
     *
     * @return ToolProperty[] Массив описаний свойств инструмента
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Поисковый запрос',
            )
        ];
    }

    /**
     * Выполняет поиск по заданному запросу и возвращает результат.
     *
     * @param string $search Поисковый запрос
     *
     * @return string JSON-строка с полем articles, содержащим список найденных статей
     */
    public function __invoke(string $search): string
    {
        $searchManager = new ArticleSearchManager($this->searchers);

        /** @var ArticleContentDto[] $articles */
        $articles = $searchManager
            ->searchAll($search, $this->defaultLimitPerSource)
            ->await();

        $resultDto = new SearchToolResultDto($articles);

        return json_encode($resultDto->toArray(), JSON_UNESCAPED_UNICODE);
    }
}