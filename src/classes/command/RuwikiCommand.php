<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\classes\search\wiki\ArticleSearchFactory;
use app\modules\neuron\classes\search\wiki\ArticleSearchManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Консольная команда поиска одной статьи в RuWiki.
 *
 * Принимает текст запроса из опции {@see --message}, выполняет поиск по
 * российской RuWiki и выводит содержимое первой найденной статьи в виде
 * очищенного текстового блока.
 *
 * Пример вызова:
 *   php bin/console ruwiki --message "Теория относительности"
 */
class RuwikiCommand extends Command
{
    /**
     * Имя консольной команды.
     *
     * @var string|null
     */
    protected static $defaultName = 'ruwiki';

    /**
     * Настраивает команду: описание и опции.
     *
     * Опции:
     * - message — обязательный текстовый запрос для поиска статьи.
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Ищет статью в RuWiki и выводит текст первой найденной статьи.')
            ->addOption(
                'message',
                null,
                InputOption::VALUE_REQUIRED,
                'Текст запроса для поиска статьи'
            );
    }

    /**
     * Выполняет поиск статьи в RuWiki и выводит первую найденную.
     *
     * Последовательность:
     * 1. Читает и валидирует опцию message.
     * 2. Создаёт менеджер поиска через {@see ArticleSearchFactory}.
     * 3. Выполняет поиск с лимитом в одну статью.
     * 4. Выводит заголовок, URL и очищенный текст первой найденной статьи.
     *
     * @param InputInterface  $input  Ввод (опции команды).
     * @param OutputInterface $output Вывод в консоль.
     *
     * @return int Код завершения команды (Command::SUCCESS или Command::FAILURE).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $query = (string) $input->getOption('message');
        $query = trim($query);

        if ($query === '') {
            $output->writeln('<error>Не указано сообщение. Используйте --message.</error>');
            return Command::FAILURE;
        }

        try {
            $manager = $this->createSearchManager();

            /** @var ArticleContentDto[]|mixed $articles */
            $articles = $manager
                ->searchAll($query, 1)
                ->await();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (!is_array($articles) || $articles === []) {
            $output->writeln(sprintf('<info>По запросу "%s" статьи не найдены.</info>', $query));
            return Command::SUCCESS;
        }

        return $this->renderFirstArticle($articles, $output);
    }

    /**
     * Создаёт менеджер поиска статей RuWiki.
     *
     * Вынесено в отдельный метод для упрощения тестирования: в юнит‑тестах
     * можно переопределить его и вернуть тестовый менеджер.
     *
     * @return ArticleSearchManager Менеджер поиска статей RuWiki.
     */
    protected function createSearchManager(): ArticleSearchManager
    {
        return ArticleSearchFactory::createRuWikiOnlyManager();
    }

    /**
     * Выводит в консоль первую подходящую статью из списка результатов.
     *
     * @param ArticleContentDto[] $articles Массив найденных статей.
     * @param OutputInterface     $output   Объект вывода в консоль.
     *
     * @return int Command::SUCCESS.
     */
    private function renderFirstArticle(array $articles, OutputInterface $output): int
    {
        $article = $this->findFirstArticle($articles);

        if ($article === null) {
            $output->writeln('<info>Подходящие статьи не найдены.</info>');
            return Command::SUCCESS;
        }

        $plainText = $this->convertHtmlToPlainText($article->content);

        $output->writeln(sprintf('=== %s ===', $article->title));
        $output->writeln(sprintf('Source: %s', $article->sourceUrl));
        $output->writeln('');
        $output->writeln($plainText);

        return Command::SUCCESS;
    }

    /**
     * Находит первую корректную статью в массиве результатов.
     *
     * @param mixed[] $articles Массив результатов поиска.
     *
     * @return ArticleContentDto|null Первая подходящая статья или null.
     */
    private function findFirstArticle(array $articles): ?ArticleContentDto
    {
        foreach ($articles as $candidate) {
            if ($candidate instanceof ArticleContentDto) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Преобразует HTML-содержимое статьи в простой текст без разметки.
     *
     * @param string $html HTML-контент статьи.
     *
     * @return string Очищенный текст.
     */
    private function convertHtmlToPlainText(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded);

        return trim($stripped);
    }
}
