<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\classes\orchestrators\TodoListOrchestrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function json_encode;

use const JSON_UNESCAPED_UNICODE;

/**
 * Консольная команда запуска внешнего оркестратора TodoList-циклов.
 *
 * Команда загружает три сценария (`init`, `step`, `finish`) и передает их
 * в {@see TodoListOrchestrator}, который исполняет детерминированный цикл
 * с лимитом итераций и необязательными перезапусками после ошибок.
 */
final class OrchestrateCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'orchestrate';

    protected function configure(): void
    {
        $this
            ->setDescription('Запускает внешний оркестратор для init/step/finish TodoList')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Имя агента')
            ->addOption('init', null, InputOption::VALUE_REQUIRED, 'Имя init-todolist')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Имя step-todolist')
            ->addOption('finish', null, InputOption::VALUE_REQUIRED, 'Имя finish-todolist')
            ->addOption('session_id', null, InputOption::VALUE_OPTIONAL, 'Ключ сессии')
            ->addOption('max_iters', null, InputOption::VALUE_OPTIONAL, 'Макс. число итераций step', '100')
            ->addOption('restart_on_fail', null, InputOption::VALUE_NONE, 'Разрешить перезапуск цикла при ошибках')
            ->addOption('max_restarts', null, InputOption::VALUE_OPTIONAL, 'Макс. число перезапусков', '0')
            ->addOption('log_level', null, InputOption::VALUE_OPTIONAL, 'off|minimal|normal|debug', 'normal')
            ->addOption('quiet_logs', null, InputOption::VALUE_NONE, 'Отключить логи оркестратора')
            ->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Сессионный параметр date для плейсхолдера $date')
            ->addOption(
                'branch',
                null,
                InputOption::VALUE_OPTIONAL,
                'Сессионный параметр branch для плейсхолдера $branch'
            )
            ->addOption('user', null, InputOption::VALUE_OPTIONAL, 'Сессионный параметр user для плейсхолдера $user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName = (string) ($input->getOption('agent') ?? '');
        $initName = (string) ($input->getOption('init') ?? '');
        $stepName = (string) ($input->getOption('step') ?? '');
        $finishName = (string) ($input->getOption('finish') ?? '');
        $sessionId = (string) ($input->getOption('session_id') ?? '');

        if ($agentName === '' || $initName === '' || $stepName === '' || $finishName === '') {
            $output->writeln('<error>Нужно указать --agent, --init, --step, --finish.</error>');
            return Command::FAILURE;
        }

        $configApp = ConfigurationApp::getInstance();
        if ($sessionId !== '') {
            if (!ConfigurationApp::isValidSessionKey($sessionId)) {
                $output->writeln('<error>Неверный формат --session_id.</error>');
                return Command::FAILURE;
            }
            if (!ConfigurationApp::getInstance()->sessionExists($sessionId)) {
                $output->writeln(sprintf('<error>Сессия с session_id "%s" не найдена.</error>', $sessionId));
                return Command::FAILURE;
            }
            $configApp->setSessionKey($sessionId);
        }

        $this->resolveFileLogger($configApp);

        $agentCfg = $configApp->getAgent($agentName);
        if ($agentCfg === null) {
            $output->writeln(sprintf('<error>Агент "%s" не найден.</error>', $agentName));
            return Command::FAILURE;
        }

        $init = $configApp->getTodoList($initName);
        $step = $configApp->getTodoList($stepName);
        $finish = $configApp->getTodoList($finishName);
        if ($init === null || $step === null || $finish === null) {
            $output->writeln('<error>Один из TodoList не найден. Проверьте --init/--step/--finish.</error>');
            return Command::FAILURE;
        }

        $init->setDefaultConfigurationAgent($agentCfg);
        $step->setDefaultConfigurationAgent($agentCfg);
        $finish->setDefaultConfigurationAgent($agentCfg);

        $sessionParamsDto = null;
        $dateOption = (string) ($input->getOption('date') ?? '');
        $branchOption = (string) ($input->getOption('branch') ?? '');
        $userOption = (string) ($input->getOption('user') ?? '');
        if ($dateOption !== '' || $branchOption !== '' || $userOption !== '') {
            $sessionParamsDto = (new SessionParamsDto())
                ->setDate($dateOption !== '' ? $dateOption : null)
                ->setBranch($branchOption !== '' ? $branchOption : null)
                ->setUser($userOption !== '' ? $userOption : null);
        }

        $maxIterations = max(1, (int) $input->getOption('max_iters'));
        $restartOnFail = (bool) $input->getOption('restart_on_fail');
        $maxRestarts = max(0, (int) $input->getOption('max_restarts'));
        $quietLogs = (bool) $input->getOption('quiet_logs');
        $logLevel = (string) ($input->getOption('log_level') ?? TodoListOrchestrator::LOG_NORMAL);

        $orchestrator = new TodoListOrchestrator(
            $configApp,
            $configApp->getLoggerWithContext(),
            !$quietLogs,
            $logLevel
        );

        try {
            $result = $orchestrator->run(
                $init,
                $step,
                $finish,
                $maxIterations,
                $restartOnFail,
                $maxRestarts,
                $sessionParamsDto
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>Ошибка оркестратора: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln((string) json_encode($result->toArray(), JSON_UNESCAPED_UNICODE));

        return $result->isSuccess() ? Command::SUCCESS : Command::FAILURE;
    }
}
