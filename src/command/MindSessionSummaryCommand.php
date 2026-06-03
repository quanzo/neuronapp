<?php

declare(strict_types=1);

namespace app\modules\neuron\command;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\interfaces\MindSessionSummaryRefresherInterface;
use app\modules\neuron\mind\dto\config\MindConfigDto;
use app\modules\neuron\mind\helpers\MindSessionSummaryCliConfigHelper;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\services\MindSessionSummaryService;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\UserMindStorage;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Консольная команда принудительного пересчёта LLM-summary сессии в `.mind`.
 *
 * Не требует блока `mind` в config приложения: агент-суммаризатор и параметры summary
 * задаются в CLI. Блок `mind` в config используется только автоматическим подписчиком.
 *
 * Примеры:
 *
 * <code>
 * php bin/console mind:summary --session_id 20250301-143022-123456-0 --agent my_summarizer_agent
 * php bin/console mind:summary --session_id 20250301-143022-123456-0 --agent my_summarizer_agent \
 *   --max-summary-chars 400 --transcript-ratio 0.3
 * </code>
 */
class MindSessionSummaryCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'mind:summary';

    protected function configure(): void
    {
        $this
            ->setDescription('Пересчитывает LLM-summary сессии в долговременной памяти .mind')
            ->addOption(
                'session_id',
                null,
                InputOption::VALUE_REQUIRED,
                'Ключ сессии (формат buildSessionKey)',
            )
            ->addOption(
                'agent',
                null,
                InputOption::VALUE_REQUIRED,
                'Имя агента-суммаризатора (agents/*.php, например my_summarizer_agent)',
            )
            ->addOption(
                'max-summary-chars',
                null,
                InputOption::VALUE_OPTIONAL,
                'Максимальная длина summary в индексе (UTF-8, не меньше 50)',
            )
            ->addOption(
                'transcript-ratio',
                null,
                InputOption::VALUE_OPTIONAL,
                'Доля контекстного окна агента под транскрипт (0.05–0.5)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = trim((string) ($input->getOption('session_id') ?? ''));
        $agentName = trim((string) ($input->getOption('agent') ?? ''));

        if ($sessionId === '') {
            $output->writeln('<error>Не указан --session_id.</error>');

            return Command::FAILURE;
        }

        if ($agentName === '') {
            $output->writeln('<error>Не указан --agent (имя агента-суммаризатора).</error>');

            return Command::FAILURE;
        }

        if (MindSummarySessionKeyHelper::isSummarySession($sessionId)) {
            $output->writeln(
                '<error>Нельзя суммаризировать служебную сессию mind-summary. Укажите ключ основной сессии.</error>',
            );

            return Command::FAILURE;
        }

        if (!ConfigurationApp::isValidSessionKey($sessionId)) {
            $output->writeln(sprintf(
                '<error>Неверный формат --session_id. Ожидается формат %s.</error>',
                ConfigurationApp::describeSessionKeyFormat(),
            ));

            return Command::FAILURE;
        }

        try {
            $summaryConfig = MindSessionSummaryCliConfigHelper::fromInput($input, $agentName);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $app = ConfigurationApp::getInstance();
        $app->setSessionKey($sessionId);
        $this->resolveFileLogger($app);

        $summarizer = $this->resolveSummarizerAgent($app, $agentName);
        if ($summarizer === null) {
            $output->writeln(sprintf('<error>Агент-суммаризатор "%s" не найден.</error>', $agentName));

            return Command::FAILURE;
        }

        $effective = new MindConfigDto(null, $summaryConfig);

        $paths = new MindPaths($app->getMindDir(), $app->getUserId());
        $mind = new UserMindStorage($paths);
        $meta = $mind->getSessionsIndex()->get($sessionId);
        if ($meta === null) {
            $output->writeln(sprintf(
                '<error>Сессия "%s" отсутствует в индексе .mind (sessions.md).</error>',
                $sessionId,
            ));

            return Command::FAILURE;
        }

        if ($meta->getMessageCount() === 0) {
            $output->writeln(sprintf(
                '<error>В сессии "%s" нет сообщений в .mind (messageCount=0).</error>',
                $sessionId,
            ));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('SessionKey: <info>%s</info>', $sessionId));
        $output->writeln(sprintf('Агент-суммаризатор: <info>%s</info>', $agentName));
        $output->writeln(sprintf('Сообщений в .mind: <info>%d</info>', $meta->getMessageCount()));

        $service = $this->createSummaryRefresher($app, $effective);
        $updated = $mind->refreshSessionSummary($app, $sessionId, $service, $effective);

        $metaAfter = $mind->getSessionsIndex()->get($sessionId);
        $summaryAfter = $metaAfter?->getSummary() ?? '';

        if ($summaryAfter === '') {
            $output->writeln('<error>Summary не получен (проверьте агента-суммаризатора и логи).</error>');

            return Command::FAILURE;
        }

        if ($updated) {
            $output->writeln('<info>Summary обновлён.</info>');
        } else {
            $output->writeln('<comment>Summary не изменился (уже актуален).</comment>');
        }

        $output->writeln('');
        $output->writeln($summaryAfter);

        return Command::SUCCESS;
    }

    /**
     * Загружает шаблон агента-суммаризатора по имени (переопределяется в тестах).
     */
    protected function resolveSummarizerAgent(ConfigurationApp $app, string $agentName): ?ConfigurationAgent
    {
        return $app->getAgent($agentName);
    }

    /**
     * Создаёт сервис суммаризации (переопределяется в тестах).
     */
    protected function createSummaryRefresher(
        ConfigurationApp $app,
        MindConfigDto $effective,
    ): MindSessionSummaryRefresherInterface {
        return MindSessionSummaryService::fromMindConfig($effective, $app);
    }
}
