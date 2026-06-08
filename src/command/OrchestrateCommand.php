<?php

declare(strict_types=1);

namespace app\modules\neuron\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\console\OutputDto;
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\classes\orchestrators\TodoListOrchestrator;
use app\modules\neuron\helpers\ConsoleHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Формат вывода. Доступно: md, txt, json', 'json')
            ->addOption('max_iters', null, InputOption::VALUE_OPTIONAL, 'Макс. число итераций step', '100')
            ->addOption('restart_on_fail', null, InputOption::VALUE_NONE, 'Разрешить перезапуск цикла при ошибках')
            ->addOption('max_restarts', null, InputOption::VALUE_OPTIONAL, 'Макс. число перезапусков', '0')
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
        $agentName  = (string) ($input->getOption('agent') ?? '');
        $initName   = (string) ($input->getOption('init') ?? '');
        $stepName   = (string) ($input->getOption('step') ?? '');
        $finishName = (string) ($input->getOption('finish') ?? '');
        $sessionId  = (string) ($input->getOption('session_id') ?? '');

        $formatResolved = ConsoleHelper::resolveFormat($input->getOption('format'), 'json');
        if ($formatResolved instanceof OutputDto) {
            return $this->finish($output, $formatResolved, 'json');
        }
        $formatOut = $formatResolved;

        if ($agentName === '' || $initName === '' || $stepName === '' || $finishName === '') {
            return $this->finish($output, OutputDto::fromError(
                'Нужно указать --agent, --init, --step, --finish.',
                $sessionId,
            ), $formatOut);
        }

        $configApp = ConfigurationApp::getInstance();
        if ($sessionId !== '') {
            if (!ConfigurationApp::isValidSessionKey($sessionId)) {
                return $this->finish($output, OutputDto::fromInvalidSessionKey($sessionId, '--session_id'), $formatOut);
            }
            if (!ConfigurationApp::getInstance()->sessionExists($sessionId)) {
                return $this->finish($output, OutputDto::fromSessionNotFound($sessionId), $formatOut);
            }
            $configApp->setSessionKey($sessionId);
        }

        $this->resolveFileLogger($configApp);

        $agentCfg = $configApp->getAgent($agentName);
        if ($agentCfg === null) {
            return $this->finish($output, OutputDto::fromAgentNotFound($agentName, $configApp->getSessionKey()), $formatOut);
        }

        $init   = $configApp->getTodoList($initName);
        $step   = $configApp->getTodoList($stepName);
        $finish = $configApp->getTodoList($finishName);
        if ($init === null || $step === null || $finish === null) {
            return $this->finish($output, OutputDto::fromError(
                'Один из TodoList не найден. Проверьте --init/--step/--finish.',
                $configApp->getSessionKey(),
            ), $formatOut);
        }

        $init->setDefaultConfigurationAgent($agentCfg);
        $step->setDefaultConfigurationAgent($agentCfg);
        $finish->setDefaultConfigurationAgent($agentCfg);

        $sessionParamsDto = null;
        $dateOption       = (string) ($input->getOption('date') ?? '');
        $branchOption     = (string) ($input->getOption('branch') ?? '');
        $userOption       = (string) ($input->getOption('user') ?? '');
        if ($dateOption !== '' || $branchOption !== '' || $userOption !== '') {
            $sessionParamsDto = (new SessionParamsDto())
                ->setDate($dateOption !== '' ? $dateOption : null)
                ->setBranch($branchOption !== '' ? $branchOption : null)
                ->setUser($userOption !== '' ? $userOption : null);
        }

        $maxIterations = max(1, (int) $input->getOption('max_iters'));
        $restartOnFail = (bool) $input->getOption('restart_on_fail');
        $maxRestarts   = max(0, (int) $input->getOption('max_restarts'));

        $orchestrator = new TodoListOrchestrator($configApp);

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
            return $this->finish($output, OutputDto::fromException($e, $agentCfg), $formatOut);
        }

        return $this->finish($output, OutputDto::fromOrchestrator($result), $formatOut);
    }
}
