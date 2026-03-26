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
 * Консольная команда очистки всех сессий приложения.
 *
 * Команда собирает sessionKey как union по:
 * - `.sessions` (по файлам `neuron_*.chat`);
 * - `.store` (по `run_state_*.json` и `var_index_*.json`);
 * - `.logs` (по `*.log`).
 *
 * Затем для каждого sessionKey удаляет все связанные файлы (историю, чекпоинты, var-результаты, логи).
 *
 * Примеры:
 *
 * - Dry-run (показать, что будет удалено):
 *   `php bin/console sessions:clear --dry-run`
 *
 * - Удалить без подтверждения:
 *   `php bin/console sessions:clear --yes`
 */
final class ClearAllSessionsCommand extends Command
{
    protected static $defaultName = 'sessions:clear';

    protected function configure(): void
    {
        $this
            ->setDescription('Очищает все сессии (union по .sessions/.store/.logs)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, что будет удалено, без удаления')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Не спрашивать подтверждение');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $yes = (bool) $input->getOption('yes');

        $appCfg = ConfigurationApp::getInstance();
        $sessionKeys = SessionCleanupHelper::listAllSessionKeysUnion($appCfg);

        $output->writeln(sprintf('Найдено sessionKey (union): <info>%d</info>', count($sessionKeys)));
        foreach ($sessionKeys as $k) {
            $output->writeln(' - ' . $k);
        }

        if ($sessionKeys === []) {
            $output->writeln('<comment>Сессии не найдены.</comment>');
            return Command::SUCCESS;
        }

        if (!$dryRun && !$yes) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>Команда запущена в non-interactive режиме. Укажите --yes для подтверждения.</error>');
                return Command::FAILURE;
            }

            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Удалить ВСЕ сессии и связанные файлы? (y/N) ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Отменено.</comment>');
                return Command::SUCCESS;
            }
        }

        $totalDeleted = 0;
        $totalMissing = 0;
        $totalErrors = 0;

        foreach ($sessionKeys as $sessionKey) {
            $output->writeln('');
            $output->writeln(sprintf('== %s ==', $sessionKey));

            $res = SessionCleanupHelper::clearSession($appCfg, $sessionKey, $dryRun);

            $totalDeleted += $res->getDeletedFilesCount();
            $totalMissing += $res->getMissingFilesCount();
            $totalErrors += $res->getErrorsCount();

            if ($dryRun) {
                $output->writeln(sprintf('Dry-run candidates: %d', $res->getPlannedFilesCount()));
            } else {
                $output->writeln(sprintf('Deleted: %d', $res->getDeletedFilesCount()));
            }

            if ($res->getErrorsCount() > 0) {
                foreach ($res->getErrors() as $err) {
                    $output->writeln('<error>' . $err . '</error>');
                }
            }
        }

        $output->writeln('');
        $output->writeln('== Итог ==');
        if ($dryRun) {
            $output->writeln('<comment>Dry-run: файлы не удалялись.</comment>');
        }
        $output->writeln(sprintf('TotalDeleted: <info>%d</info>', $totalDeleted));
        $output->writeln(sprintf('TotalMissing: <info>%d</info>', $totalMissing));
        $output->writeln(sprintf('TotalErrors: <info>%d</info>', $totalErrors));

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
