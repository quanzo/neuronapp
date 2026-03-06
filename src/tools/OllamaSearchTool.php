<?php

namespace app\modules\neuron\tools;

use app\modules\neuron\services\ollama\OllamaApiService;
use app\modules\neuron\classes\search\ollama\OllamaArticleSearcher;

/**
 * Поиск web страниц по текстовому запросу используя ollama api
 */
class OllamaSearchTool extends UniSearchTool
{
    /**
     * Базовый URL Ollama Web Search API.
     */
    private const OLLAMA_BASE_URL = 'https://ollama.com';

    /**
     * Конструктор инструмента поиска web-страниц через Ollama.
     *
     * @param string $ollamaApiKey API-ключ для доступа к Ollama Web Search API
     */
    public function __construct(
        protected string $ollamaApiKey = '',
    ) {
        $apiService = new OllamaApiService(
            self::OLLAMA_BASE_URL,
            $this->ollamaApiKey
        );

        $searchers = [
            new OllamaArticleSearcher(
                $apiService
            )
        ];
        parent::__construct(
            'ollama_search',
            'Выполняет поиск в интернете терминов, определений и другой различной информации. Поддерживает поиск и загрузку веб-страниц.',
            $searchers
        );
    }
}
