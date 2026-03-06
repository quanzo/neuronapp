<?php

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\loader\wiki\RuWikiFullLoader;
use app\modules\neuron\classes\search\wiki\RuWikiArticleSearcher;

/**
 * Инструмент для поиска страниц в российской RuWiki.
 * Использует специализированный поисковик {@see RuWikiArticleSearcher}.
 */
class RuWikiSearchTool extends UniSearchTool
{
    /**
     * Конструктор инструмента поиска по RuWiki.
     */
    public function __construct()
    {
        $wikiLoader = new RuWikiFullLoader();
        $searchers = [
            new RuWikiArticleSearcher($wikiLoader),
        ];
        parent::__construct(
            'ru_wiki_search',
            'Выполняет поиск в терминов, определений и другой различной информации. Поддерживает поиск и загрузку страниц в российской RuWiki.',
            $searchers
        );
    }
}
