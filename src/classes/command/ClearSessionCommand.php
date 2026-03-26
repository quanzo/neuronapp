<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\helpers\SessionCleanupHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Консольная команда очистки конкретной сессии.
 *
 * Удаляет все файлы, связанные с указанным sessionKey:
 * - `.sessions/neuron_<sessionKey>*.chat`;
 * - `.store/run_state_<sessionKey>_*.json`;
 * - `.store/var_<sessionKey>_*.json` и `.store/var_index_<sessionKey>.json`;
 * - `.logs/<sessionKey>.log`.
 *
 * Примеры:
 *
 * - Dry-run (показать, что будет удалено):
 *   `php bin/console session:clear --session_id 20250301-143022-123456-0 --dry-run`
 *
 * - Удалить без подтверждения:
 *   `php bin/console session:clear --session_id 20250301-143022-123456-0 --yes`
 */
final class ClearSessionCommand extends Command
{
    protected static $defaultName = 'session:clear';

    protected function configure(): void
    {
        $this
            ->setDescription('Очищает одну сессию (история, .store, логи)')
            ->addOption('session_id', null, InputOption::VALUE_REQUIRED, 'Ключ сессии (формат buildSessionKey)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, что будет удалено, без удаления')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Не спрашивать подтверждение');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = (string) ($input->getOption('session_id') ?? '');
        $dryRun = (bool) $input->getOption('dry-run');
        $yes = (bool) $input->getOption('yes');

        if ($sessionId === '') {
            $output->writeln('<error>Не указан --session_id.</error>');
            return Command::FAILURE;
        }

        if (!ConfigurationApp::isValidSessionKey($sessionId)) {
            $output->writeln('<error>Неверный формат --session_id. Ожидается формат Ymd-His-u-userId (например, 20250301-143022-123456-0).</error>');
            return Command::FAILURE;
        }

        $appCfg = ConfigurationApp::getInstance();
        $candidates = SessionCleanupHelper::buildSessionFileCandidates($appCfg, $sessionId);

        $output->writeln(sprintf('SessionKey: <info>%s</info>', $sessionId));
        $output->writeln(sprintf('Найдено кандидатов на удаление: <info>%d</info>', count($candidates)));
        foreach ($candidates as $path) {
            $output->writeln(' - ' . $path);
        }

        if ($dryRun) {
            $result = SessionCleanupHelper::clearSession($appCfg, $sessionId, true);
            $output->writeln('<comment>Dry-run: файлы не удалялись.</comment>');
            $output->writeln(sprintf('Missing: %d, Errors: %d', $result->getMissingFilesCount(), $result->getErrorsCount()));
            foreach ($result->getErrors() as $err) {
                $output->writeln('<error>' . $err . '</error>');
            }
            return Command::SUCCESS;
        }

        if (!$yes) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>Команда запущена в non-interactive режиме. Укажите --yes для подтверждения.</error>');
                return Command::FAILURE;
            }

            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Удалить эти файлы? (y/N) ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Отменено.</comment>');
                return Command::SUCCESS;
            }
        }

        $result = SessionCleanupHelper::clearSession($appCfg, $sessionId, false);

        $output->writeln(sprintf('Deleted: <info>%d</info>', $result->getDeletedFilesCount()));
        $output->writeln(sprintf('Missing: <info>%d</info>', $result->getMissingFilesCount()));
        $output->writeln(sprintf('Errors: <info>%d</info>', $result->getErrorsCount()));
        foreach ($result->getErrors() as $err) {
            $output->writeln('<error>' . $err . '</error>');
        }

        return $result->getErrorsCount() > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
