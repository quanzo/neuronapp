<?php

declare(strict_types=1);

namespace app\modules\neuron\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\console\OutputDto;
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\helpers\AttachmentHelper;
use app\modules\neuron\helpers\ConsoleHelper;
use app\modules\neuron\helpers\TodoListResumeHelper;
use NeuronAI\Chat\Enums\MessageRole;
use Revolt\EventLoop;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Консольная команда выполнения списка заданий TodoList через указанного агента.
 *
 * По имени загружает список из папки todos/ (через {@see ConfigurationApp::getTodoList()}),
 * исполняет его с помощью агента из опции {@see --agent} через
 * {@see TodoList::execute()}, выводит итоговый ответ агента и
 * {@see sessionKey} для продолжения сессии.
 *
 * Примеры вызова:
 *   php bin/console todolist --todolist code-review --agent default
 *   php bin/console todolist --todolist research-topic --agent default --session_id 20250301-143022-123456
 *   php bin/console todolist --todolist code-review --agent default --session_id 20250301-143022-123456 --resume
 *   php bin/console todolist --agent default --session_id 20250301-143022-123456 --abort
 */
class TodolistCommand extends AbstractAgentCommand
{
    /** Имя команды в консоли. */
    protected static $defaultName = 'todolist';

    /**
     * Настраивает команду: описание и опции.
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Выполняет список заданий TodoList через указанного агента и выводит ответ с sessionKey')
            ->addOption('todolist', null, InputOption::VALUE_REQUIRED, 'Имя списка заданий (например, code-review)')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Имя агента LLM для исполнения (например, default)')
            ->addOption('session_id', null, InputOption::VALUE_OPTIONAL, 'Ключ сессии для продолжения (формат buildSessionKey)')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Продолжить выполнение с последнего чекпоинта')
            ->addOption('abort', null, InputOption::VALUE_NONE, 'Сбросить состояние незавершённого run для сессии')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Формат вывода. Доступно: md, txt, json', 'md')
            ->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Сессионный параметр date для плейсхолдера $date')
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'Сессионный параметр branch для плейсхолдера $branch')
            ->addOption('user', null, InputOption::VALUE_OPTIONAL, 'Сессионный параметр user для плейсхолдера $user')
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Путь к файлу для прикрепления (можно указать несколько раз)'
            );
    }

    /**
     * Выполняет команду: загрузка TodoList и агента, опциональная подстановка session_id, исполнение, вывод результата.
     *
     * @return int Command::SUCCESS или Command::FAILURE.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $todolistName = (string) ($input->getOption('todolist') ?? '');
        $agentName    = (string) ($input->getOption('agent') ?? '');
        $sessionId    = (string) ($input->getOption('session_id') ?? '');
        $resume       = (bool) $input->getOption('resume');
        $abort        = (bool) $input->getOption('abort');
        $fileOptions  = $input->getOption('file');
        $dateOption   = $input->getOption('date');
        $branchOption = $input->getOption('branch');
        $userOption   = $input->getOption('user');

        $formatResolved = ConsoleHelper::resolveFormat($input->getOption('format'), 'md');
        if ($formatResolved instanceof OutputDto) {
            return $this->finish($output, $formatResolved, 'md');
        }
        $formatOut = $formatResolved;

        if ($agentName === '') {
            return $this->finish($output, OutputDto::fromMissingAgentOption($sessionId), $formatOut);
        }

        $configApp = ConfigurationApp::getInstance();

        if ($sessionId !== '') {
            if (!ConfigurationApp::isValidSessionKey($sessionId)) {
                return $this->finish($output, OutputDto::fromInvalidSessionKey($sessionId), $formatOut);
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

        if ($abort) {
            $runStateDto = $agentCfg->getExistRunStateDto();
            if ($runStateDto) {
                $runStateDto->delete();

                return $this->finish($output, OutputDto::fromResponse(
                    sprintf('Состояние незавершённого run для сессии "%s" и агента "%s" сброшено.', $sessionId, $agentName),
                    $configApp->getSessionKey(),
                ), $formatOut);
            }

            return $this->finish($output, OutputDto::fromError(
                'Для --abort необходимо наличие незавершенной сессии. Команда в сессии завершена или неправильно задан --session_id.',
                $configApp->getSessionKey(),
            ), $formatOut);
        }

        if ($todolistName === '') {
            return $this->finish($output, OutputDto::fromError(
                'Не указан список заданий. Используйте --todolist.',
                $configApp->getSessionKey(),
            ), $formatOut);
        }

        $attachments = [];
        if (is_array($fileOptions) && $fileOptions !== []) {
            $buildResult = AttachmentHelper::buildAttachmentsFromPaths($fileOptions);
            if ($buildResult->isError()) {
                return $this->finish($output, OutputDto::fromError($buildResult->getErrorMessage(), $configApp->getSessionKey()), $formatOut);
            }
            $attachments = $buildResult->getAttachments();
        }

        $todoList = $configApp->getTodoList($todolistName);

        if ($todoList === null) {
            return $this->finish($output, OutputDto::fromError(
                sprintf('TodoList "%s" не найден.', $todolistName),
                $configApp->getSessionKey(),
            ), $formatOut);
        }

        $startFromTodoIndex = 0;

        if (!$resume && !$abort && $sessionId !== '') {
            $runStateDto = $agentCfg->getExistRunStateDto();
            if ($runStateDto) {
                return $this->finish($output, OutputDto::fromUnfinishedRun(
                    $runStateDto->getTodolistName(),
                    $configApp->getSessionKey(),
                    true,
                ), $formatOut);
            }
        }

        if ($resume) {
            $plan = TodoListResumeHelper::buildPlan($agentCfg, $todolistName, $configApp->getSessionKey());

            if (!$plan->isResumeAvailable()) {
                if ($plan->getReason() === 'finished') {
                    return $this->finish($output, OutputDto::fromError(
                        'Выполнение списка уже завершено; продолжение недоступно.',
                        $configApp->getSessionKey(),
                    ), $formatOut);
                }

                if ($plan->getReason() === 'todolist_mismatch') {
                    return $this->finish($output, OutputDto::fromError(
                        sprintf(
                            'Продолжить можно только тот же список: в чекпоинте "%s", указан "%s".',
                            $plan->getRunStateDto()?->getTodolistName() ?? '',
                            $todolistName
                        ),
                        $configApp->getSessionKey(),
                    ), $formatOut);
                }

                return $this->finish($output, OutputDto::fromError(
                    'Нет сохранённого состояния для продолжения (чекпоинт не найден).',
                    $configApp->getSessionKey(),
                ), $formatOut);
            }

            if (!TodoListResumeHelper::applyHistoryRollback($agentCfg, $plan)) {
                $logger = $agentCfg->getLogger();
                if ($logger !== null) {
                    $logger->warning('Откат истории невозможен: в чекпоинте нет history_message_count (возможен дубликат сообщения при сбое).');
                }
            }

            $startFromTodoIndex = $plan->getStartFromTodoIndex();
        }

        $todoList->setDefaultConfigurationAgent($agentCfg);

        $sessionParamsDto = null;
        if (
            ($dateOption !== null && $dateOption !== '')
            || ($branchOption !== null && $branchOption !== '')
            || ($userOption !== null && $userOption !== '')
        ) {
            $sessionParamsDto = (new SessionParamsDto())
                ->setDate($dateOption)
                ->setBranch($branchOption)
                ->setUser($userOption);
        }

        $error = null;

        EventLoop::queue(static function () use ($todoList, $attachments, $startFromTodoIndex, $sessionParamsDto, &$error): void {
            try {
                $todoList->execute(
                    MessageRole::USER,
                    $attachments,
                    null,
                    $startFromTodoIndex,
                    $sessionParamsDto
                )->await();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        EventLoop::run();

        $outDto = $error !== null
            ? OutputDto::fromException($error, $agentCfg)
            : OutputDto::fromAgent($agentCfg);

        return $this->finish($output, $outDto, $formatOut);
    }
}
