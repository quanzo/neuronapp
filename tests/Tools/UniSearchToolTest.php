<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\tools\UniSearchTool;
use app\modules\neuron\interfaces\ArticleSearcherInterface;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see UniSearchTool}.
 *
 * Покрывают базовый контракт инструмента:
 * - корректный вызов асинхронных поисковиков и агрегацию результатов;
 * - сериализацию результата в JSON со списком статей;
 * - поведение при отсутствии результатов поиска.
 */
final class UniSearchToolTest extends TestCase
{
    /**
     * Проверяет, что инструмент корректно вызывает переданный поисковик
     * и возвращает JSON с одной статьёй, где заголовок и контент
     * зависят от поискового запроса.
     */
    public function testInvokeReturnsArticlesJson(): void
    {
        $searcher = new class implements ArticleSearcherInterface {
            public function search(string $query, int $limit = 10, int $offset = 0): \Amp\Future
            {
                return \Amp\async(function () use ($query) {
                    return [
                        new ArticleContentDto(
                            content: 'content-' . $query,
                            title: 'title-' . $query,
                            sourceUrl: 'https://example.com/' . $query,
                            sourceType: ContentSourceType::WIKIPEDIA
                        ),
                    ];
                });
            }
        };

        $tool = new UniSearchTool(
            name: 'test_search',
            description: 'Тестовый инструмент поиска',
            searchers: [$searcher]
        );

        $json = $tool->__invoke('query');
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('articles', $data);
        $this->assertCount(1, $data['articles']);
        $this->assertSame('title-query', $data['articles'][0]['title']);
        $this->assertSame('content-query', $data['articles'][0]['content']);
    }

    /**
     * Проверяет, что при отсутствии результатов от поисковика
     * инструмент возвращает JSON с пустым массивом статей,
     * сохраняя общий формат ответа.
     */
    public function testInvokeWithNoResultsReturnsEmptyArticlesArray(): void
    {
        $searcher = new class implements ArticleSearcherInterface {
            public function search(string $query, int $limit = 10, int $offset = 0): \Amp\Future
            {
                return \Amp\async(static fn () => []);
            }
        };

        $tool = new UniSearchTool(
            name: 'test_search_empty',
            description: 'Тестовый инструмент поиска без результатов',
            searchers: [$searcher]
        );

        $json = $tool->__invoke('query');
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('articles', $data);
        $this->assertIsArray($data['articles']);
        $this->assertCount(0, $data['articles']);
    }
}
