<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\ArticleSearcherInterface;
use app\modules\neuron\tools\WikiSearchTool;
use PHPUnit\Framework\TestCase;

use function json_decode;

/**
 * Тесты для {@see WikiSearchTool}.
 *
 * Класс `WikiSearchTool` является тонкой обёрткой над {@see \app\modules\neuron\tools\UniSearchTool}
 * с преднастроенным набором поисковиков Wikipedia. В тестах мы проверяем:
 * - что инструмент совместим с контрактом UniSearchTool и умеет работать с
 *   переданными извне поисковиками (без реальных HTTP‑запросов);
 * - что конструктор по умолчанию создаёт хотя бы один поисковик.
 */
final class WikiSearchToolTest extends TestCase
{
    /**
     * Проверяет, что WikiSearchTool при использовании заранее
     * внедрённого поисковика возвращает корректный JSON с результатами.
     *
     * Для изоляции от сети мы создаём анонимный подкласс WikiSearchTool,
     * который в конструкторе передаёт в родительский UniSearchTool
     * тестовый поисковик, реализующий {@see ArticleSearcherInterface}.
     */
    public function testInvokeReturnsArticlesJsonWithInjectedSearcher(): void
    {
        $searcher = new class implements ArticleSearcherInterface {
            public function search(string $query, int $limit = 10, int $offset = 0): \Amp\Future
            {
                return \Amp\async(function () use ($query) {
                    return [
                        new ArticleContentDto(
                            content: 'wiki-content-' . $query,
                            title: 'wiki-title-' . $query,
                            sourceUrl: 'https://example.org/wiki/' . $query,
                            sourceType: ContentSourceType::WIKIPEDIA
                        ),
                    ];
                });
            }
        };

        $tool = new class ([$searcher]) extends WikiSearchTool {
            /**
             * @param ArticleSearcherInterface[] $searchers
             */
            public function __construct(array $searchers)
            {
                parent::__construct(
                    name: 'test_wiki_search',
                    description: 'Тестовый WikiSearchTool с внедрённым поисковиком',
                    searchers: $searchers
                );
            }
        };

        $json = $tool->__invoke('query');
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('articles', $data);
        $this->assertCount(1, $data['articles']);
        $this->assertSame('wiki-title-query', $data['articles'][0]['title']);
        $this->assertSame('wiki-content-query', $data['articles'][0]['content']);
    }

    /**
     * Проверяет, что конструктор WikiSearchTool по умолчанию
     * инициализирует внутренний список поисковиков.
     *
     * Тест не выполняет реальные HTTP‑запросы, а только инспектирует
     * защищённое свойство `searchers` через Reflection API.
     */
    public function testDefaultConstructorCreatesSearchers(): void
    {
        $tool = new WikiSearchTool();

        $reflection = new \ReflectionClass($tool);
        $property = $reflection->getProperty('searchers');
        $property->setAccessible(true);

        $searchers = $property->getValue($tool);

        $this->assertIsArray($searchers);
        $this->assertNotEmpty($searchers);
        $this->assertContainsOnlyInstancesOf(ArticleSearcherInterface::class, $searchers);
    }
}
