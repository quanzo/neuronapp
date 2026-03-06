<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\classes\search\wiki\RuWikiArticleSearcher;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\ArticleSearcherInterface;
use app\modules\neuron\tools\RuWikiSearchTool;
use PHPUnit\Framework\TestCase;

use function json_decode;

/**
 * Тесты для {@see RuWikiSearchTool}.
 *
 * Этот инструмент специализируется на поиске в российской RuWiki
 * и конфигурирует {@see RuWikiArticleSearcher} с соответствующим загрузчиком.
 * В тестах мы проверяем:
 * - что конструктор по умолчанию действительно создаёт RuWikiArticleSearcher;
 * - что инструмент совместим с контрактом UniSearchTool и корректно работает
 *   с внедрённым тестовым поисковиком без реальных HTTP‑запросов.
 */
final class RuWikiSearchToolTest extends TestCase
{
    /**
     * Проверяет, что конструктор RuWikiSearchTool по умолчанию
     * создаёт один поисковик типа {@see RuWikiArticleSearcher}.
     *
     * Для этого мы читаем защищённое свойство `searchers` через Reflection API,
     * не вызывая сетевые операции.
     */
    public function testDefaultConstructorCreatesRuWikiSearcher(): void
    {
        $tool = new RuWikiSearchTool();

        $reflection = new \ReflectionClass($tool);
        $property = $reflection->getProperty('searchers');
        $property->setAccessible(true);

        $searchers = $property->getValue($tool);

        $this->assertIsArray($searchers);
        $this->assertCount(1, $searchers);
        $this->assertInstanceOf(RuWikiArticleSearcher::class, $searchers[0]);
    }

    /**
     * Проверяет, что RuWikiSearchTool при использовании внедрённого
     * тестового поисковика возвращает корректный JSON с результатами.
     *
     * Как и в тесте для WikiSearchTool, мы создаём анонимный подкласс
     * RuWikiSearchTool, передавая в базовый UniSearchTool собственный
     * поисковик, реализующий {@see ArticleSearcherInterface}.
     */
    public function testInvokeReturnsArticlesJsonWithInjectedSearcher(): void
    {
        $searcher = new class implements ArticleSearcherInterface {
            public function search(string $query, int $limit = 10, int $offset = 0): \Amp\Future
            {
                return \Amp\async(function () use ($query) {
                    return [
                        new ArticleContentDto(
                            content: 'ruwiki-content-' . $query,
                            title: 'ruwiki-title-' . $query,
                            sourceUrl: 'https://ruwiki.example.org/wiki/' . $query,
                            sourceType: ContentSourceType::RUWIKI
                        ),
                    ];
                });
            }
        };

        $tool = new class ([$searcher]) extends RuWikiSearchTool {
            /**
             * @param ArticleSearcherInterface[] $searchers
             */
            public function __construct(array $searchers)
            {
                parent::__construct();

                $reflection = new \ReflectionClass($this);
                $property = $reflection->getParentClass()->getProperty('searchers');
                $property->setAccessible(true);
                $property->setValue($this, $searchers);
            }
        };

        $json = $tool->__invoke('query');
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('articles', $data);
        $this->assertCount(1, $data['articles']);
        $this->assertSame('ruwiki-title-query', $data['articles'][0]['title']);
        $this->assertSame('ruwiki-content-query', $data['articles'][0]['content']);
    }
}

