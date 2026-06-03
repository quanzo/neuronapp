<?php

declare(strict_types=1);

namespace app\modules\neuron\command;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\interfaces\MindSessionSummaryRefresherInterface;
use app\modules\neuron\mind\dto\config\MindConfigDto;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\services\MindSessionSummaryService;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\UserMindStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Консольная команда принудительного пересчёта LLM-summary сессии в `.mind`.
 *
 * Записывает или обновляет поле `summary` в индексе `sessions.md` для указанного sessionKey.
 * Использует effective-конфиг `mind` (app + опциональный merge от `--agent`).
 *
 * Примеры:
 *
 * <code>
 * php bin/console mind:summary --session_id 20250301-143022-123456-0
 * php bin/console mind:summary --session_id 20250301-143022-123456-0 --agent default
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
                InputOption::VALUE_OPTIONAL,
                'Имя агента для merge блока mind (app + agent)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = (string) ($input->getOption('session_id') ?? '');
        $agentName = $input->getOption('agent');
        $agentName = $agentName !== null && $agentName !== '' ? (string) $agentName : null;

        if ($sessionId === '') {
            $output->writeln('<error>Не указан --session_id.</error>');

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

        $app = ConfigurationApp::getInstance();
        $app->setSessionKey($sessionId);
        $this->resolveFileLogger($app);

        $agentCfg = null;
        if ($agentName !== null) {
            $agentCfg = $app->getAgent($agentName);
            if ($agentCfg === null) {
                $output->writeln(sprintf('<error>Агент "%s" не найден.</error>', $agentName));

                return Command::FAILURE;
            }
        }

        $effective = MindConfigDto::resolveEffective($app, $agentCfg);
        if ($effective->resolveSessionSummary()->resolveAgent() === '') {
            $output->writeln(
                '<error>Не задан mind.session_summary.agent в конфигурации (app или agent после merge).</error>',
            );

            return Command::FAILURE;
        }

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
     * Создаёт сервис суммаризации (переопределяется в тестах).
     */
    protected function createSummaryRefresher(
        ConfigurationApp $app,
        MindConfigDto $effective,
    ): MindSessionSummaryRefresherInterface {
        return MindSessionSummaryService::fromMindConfig($effective, $app);
    }
}
