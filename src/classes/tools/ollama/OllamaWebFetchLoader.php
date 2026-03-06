<?php

namespace app\modules\neuron\classes\tools\ollama;

use Amp\Future;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\services\ollama\OllamaApiService;
use app\modules\neuron\classes\tools\wiki\ContentLoaderInterface;
use app\modules\neuron\classes\tools\wiki\GenericWebLoader;

/**
 * Загрузчик контента через Ollama Web Fetch API
 * Использует Ollama для очистки и извлечения контента веб-страниц
 * 
 * Документация: https://docs.ollama.com/capabilities/web-search#web-fetch
 */
class OllamaWebFetchLoader implements ContentLoaderInterface
{
    private OllamaApiService $ollamaService;
    private bool $fallbackToGeneric;

    public function __construct(
        ?OllamaApiService $ollamaService = null,
        bool $fallbackToGeneric = false
    ) {
        $this->ollamaService = $ollamaService ?? new OllamaApiService();
        $this->fallbackToGeneric = $fallbackToGeneric;
    }

    /**
     * Проверяет, может ли загрузчик обработать URL
     * Ollama Web Fetch может обрабатывать практически любые URL
     */
    public function canLoad(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Загружает контент через Ollama Web Fetch API
     */
    public function load(string $url): Future
    {
        return \Amp\async(function () use ($url) {
            try {
                // Используем Ollama для извлечения чистого контента
                $result = $this->ollamaService->webFetch($url)->await();
                
                return new ArticleContentDto(
                    content: $result['content'],
                    title: $result['title'] ?: $this->extractTitleFromUrl($url),
                    sourceUrl: $url,
                    sourceType: ContentSourceType::GENERIC,
                    metadata: [
                        'ollama_fetched' => true,
                        'links' => $result['links'] ?? [],
                    ]
                );
            } catch (\Throwable $e) {
                if ($this->fallbackToGeneric) {
                    // Fallback на GenericWebLoader при ошибке Ollama
                    $fallbackLoader = new GenericWebLoader();
                    if ($fallbackLoader->canLoad($url)) {
                        return $fallbackLoader->load($url)->await();
                    }
                }
                
                throw new \RuntimeException(
                    "Failed to load content via Ollama Web Fetch: {$e->getMessage()}",
                    0,
                    $e
                );
            }
        });
    }

    private function extractTitleFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (isset($parsed['host'])) {
            $host = str_replace(['www.', '.com', '.org', '.ru', '.net'], '', $parsed['host']);
            return ucfirst($host) . ' Article';
        }
        
        return 'Web Article';
    }
}
