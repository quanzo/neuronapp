<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\classes\command\RuwikiCommand;
use app\modules\neuron\classes\command\WikiCommand;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\classes\search\wiki\ArticleSearchManager;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\ArticleSearcherInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

use function json_decode;

/**
 * Тесты конфигурации и базового поведения консольных команд wiki/ruwiki.
 *
 * Проверяем:
 * - наличие и тип CLI-опций message и language;
 * - корректность выполнения команд с внедрённым тестовым поисковиком
 *   без реальных HTTP‑запросов.
 */
final class WikiCommandsOptionsTest extends TestCase
{
    public function testWikiCommandHasMessageAndLanguageOptions(): void
    {
        $command = new WikiCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('message'));
        $this->assertTrue($definition->hasOption('language'));

        /** @var InputOption $message */
        $message = $definition->getOption('message');
        $this->assertTrue($message->isValueRequired());
        $this->assertFalse($message->isArray());

        /** @var InputOption $language */
        $language = $definition->getOption('language');
        $this->assertFalse($language->isValueRequired());
        $this->assertFalse($language->isArray());
        $this->assertSame('en', $language->getDefault());
    }

    public function testRuwikiCommandHasMessageOption(): void
    {
        $command = new RuwikiCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('message'));

        /** @var InputOption $message */
        $message = $definition->getOption('message');
        $this->assertTrue($message->isValueRequired());
        $this->assertFalse($message->isArray());
    }

    public function testWikiCommandPrintsFirstArticleWithInjectedManager(): void
    {
        $searcher = new class implements ArticleSearcherInterface {
            public function search(string $query, int $limit = 10, int $offset = 0): \Amp\Future
            {
                return \Amp\async(function () use ($query) {
                    return [
                        new ArticleContentDto(
                            content: '<p>wiki-content-' . $query . '</p>',
                            title: 'wiki-title-' . $query,
                            sourceUrl: 'https://en.wikipedia.org/wiki/' . $query,
                            sourceType: ContentSourceType::WIKIPEDIA
                        ),
                    ];
                });
            }
        };

        $manager = new ArticleSearchManager([$searcher]);

        $command = new class ($manager) extends WikiCommand {
            public function __construct(ArticleSearchManager $manager)
            {
                parent::__construct();
                $this->testManager = $manager;
            }

            protected function createSearchManager(string $language): ArticleSearchManager
            {
                return $this->testManager;
            }

            private ArticleSearchManager $testManager;
        };

        $input = new ArrayInput([
            '--message' => 'query',
            '--language' => 'ru',
        ]);
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $display = $output->fetch();
        $this->assertStringContainsString('wiki-title-query', $display);
        $this->assertStringContainsString('https://en.wikipedia.org/wiki/query', $display);
        $this->assertStringContainsString('wiki-content-query', $display);
        $this->assertStringNotContainsString('<p>', $display);
    }

    public function testRuwikiCommandPrintsFirstArticleWithInjectedManager(): void
    {
        $searcher = new class implements ArticleSearcherInterface {
            public function search(string $query, int $limit = 10, int $offset = 0): \Amp\Future
            {
                return \Amp\async(function () use ($query) {
                    return [
                        new ArticleContentDto(
                            content: '<b>ruwiki-content-' . $query . '</b>',
                            title: 'ruwiki-title-' . $query,
                            sourceUrl: 'https://ru.wikipedia.org/wiki/' . $query,
                            sourceType: ContentSourceType::RUWIKI
                        ),
                    ];
                });
            }
        };

        $manager = new ArticleSearchManager([$searcher]);

        $command = new class ($manager) extends RuwikiCommand {
            public function __construct(ArticleSearchManager $manager)
            {
                parent::__construct();
                $this->testManager = $manager;
            }

            protected function createSearchManager(): ArticleSearchManager
            {
                return $this->testManager;
            }

            private ArticleSearchManager $testManager;
        };

        $input = new ArrayInput([
            '--message' => 'запрос',
        ]);
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $display = $output->fetch();
        $this->assertStringContainsString('ruwiki-title-запрос', $display);
        $this->assertStringContainsString('https://ru.wikipedia.org/wiki/запрос', $display);
        $this->assertStringContainsString('ruwiki-content-запрос', $display);
        $this->assertStringNotContainsString('<b>', $display);
    }
}

