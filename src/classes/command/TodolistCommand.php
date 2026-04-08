<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\params\SessionParamsDto;
use app\modules\neuron\helpers\AttachmentHelper;
use app\modules\neuron\helpers\ConsoleHelper;
use app\modules\neuron\helpers\TodoListResumeHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
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
 * Исполнитель — всегда агент из опции --agent; в файле TodoList агент не задаётся.
 *
 * При запуске с --session_id, если в сессии есть незавершённое выполнение (чекпоинт не finished),
 * в интерактивном режиме выводится сообщение и запрос выбора: продолжить (resume) или прервать (abort).
 * В неинтерактивном режиме в этом случае команда завершается с ошибкой и подсказкой указать --resume или --abort.
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
     *
     * Опции:
     * - todolist   — имя списка заданий (файл в todos/ без расширения), обязательно (кроме --abort).
     * - agent      — имя агента LLM для исполнения, обязательно.
     * - session_id — необязательный ключ сессии для продолжения диалога; обязателен для --resume и --abort.
     * - resume     — продолжить прерванное выполнение с последнего чекпоинта (требует session_id и тот же todolist).
     * - abort      — сбросить состояние незавершённого run для сессии (требует session_id и agent); список не выполняется.
     * - file/-f    — пути к файлам, которые будут прикреплены к запросу (можно указывать несколько раз).
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
     * @param InputInterface  $input  Ввод (опции команды).
     * @param OutputInterface $output Вывод в консоль.
     *
     * @return int Command::SUCCESS или Command::FAILURE.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arFormatAvailable = [
            'md',
            'json',
            'txt'
        ];
        $todolistName = $input->getOption('todolist');
        $agentName    = $input->getOption('agent');
        $sessionId    = $input->getOption('session_id');
        $resume       = (bool) $input->getOption('resume');
        $abort        = (bool) $input->getOption('abort');
        $formatOut    = $input->getOption('format');
        $fileOptions  = $input->getOption('file');
        $dateOption   = $input->getOption('date');
        $branchOption = $input->getOption('branch');
        $userOption   = $input->getOption('user');

        if ($agentName === null || $agentName === '') {
            $output->writeln('<error>Не указан агент. Используйте --agent.</error>');
            return Command::FAILURE;
        }

        $configApp = ConfigurationApp::getInstance();

        // Если передан session_id — проверяем формат и существование сессии, затем подставляем ключ
        if ($sessionId !== null && $sessionId !== '') {
            if (!ConfigurationApp::isValidSessionKey($sessionId)) {
                $output->writeln(sprintf(
                    '<error>Неверный формат session_id. Ожидается формат %s.</error>',
                    ConfigurationApp::describeSessionKeyFormat()
                ));
                return Command::FAILURE;
            }

            if (!ConfigurationApp::getInstance()->sessionExists($sessionId)) {
                $output->writeln(sprintf('<error>Сессия с session_id "%s" не найдена.</error>', $sessionId));
                return Command::FAILURE;
            }
            $configApp->setSessionKey($sessionId);
        }

        // установим логгер
        $this->resolveFileLogger($configApp);

        $agentCfg = $configApp->getAgent($agentName);

        if ($agentCfg === null) {
            $output->writeln(sprintf('<error>Агент "%s" не найден.</error>', $agentName));
            return Command::FAILURE;
        }

        if ($abort) {
            $runStateDto = $agentCfg->getExistRunStateDto();
            if ($runStateDto) {
                $runStateDto->delete();
                $output->writeln(sprintf('Состояние незавершённого run для сессии "%s" и агента "%s" сброшено.', $sessionId, $agentName));
                return Command::SUCCESS;
            } else {
                $output->writeln('<error>Для --abort необходимо наличие незавершенной сессии. Команда в сессии завершена или неправильно задан --session_id.</error>');
                return Command::FAILURE;
            }
        }

        if ($todolistName === null || $todolistName === '') {
            $output->writeln('<error>Не указан список заданий. Используйте --todolist.</error>');
            return Command::FAILURE;
        }

        if ($formatOut === null || $formatOut === '') {
            $formatOut = 'md';
        }
        if (!in_array($formatOut, $arFormatAvailable)) {
            $output->writeln('<error>Формат вывода задан не корректно.</error>');
            return Command::FAILURE;
        }

        // Подготовка вложений (attachments) из указанных файлов, если они есть.
        $attachments = [];
        if (is_array($fileOptions) && $fileOptions !== []) {
            $attachments = AttachmentHelper::buildAttachmentsFromPaths($fileOptions, $output);
            if ($attachments === null) {
                // Сообщение об ошибке уже выведено.
                return Command::FAILURE;
            }
        }

        $todoList = $configApp->getTodoList($todolistName);

        if ($todoList === null) {
            $output->writeln(sprintf('<error>TodoList "%s" не найден.</error>', $todolistName));
            return Command::FAILURE;
        }

        $startFromTodoIndex = 0;

        // При обычном запуске с session_id проверяем незавершённый run и при необходимости спрашиваем пользователя.
        if (!$resume && !$abort && $sessionId !== null && $sessionId !== '') {
            $runStateDto = $agentCfg->getExistRunStateDto();
            if ($runStateDto) {
                $output->writeln(sprintf(
                    '<error>В сессии обнаружено незавершённое выполнение списка "%s". Укажите --resume для продолжения или --abort для сброса.</error>',
                    $runStateDto->getTodolistName()
                ));
                return Command::FAILURE;
            }
        }

        if ($resume) {
            $plan = TodoListResumeHelper::buildPlan($agentCfg, $todolistName, $configApp->getSessionKey());

            if (!$plan->isResumeAvailable()) {
                if ($plan->getReason() === 'finished') {
                    $output->writeln('<error>Выполнение списка уже завершено; продолжение недоступно.</error>');
                    return Command::FAILURE;
                }

                if ($plan->getReason() === 'todolist_mismatch') {
                    $output->writeln(sprintf(
                        '<error>Продолжить можно только тот же список: в чекпоинте "%s", указан "%s".</error>',
                        $plan->getRunStateDto()?->getTodolistName() ?? '',
                        $todolistName
                    ));
                    return Command::FAILURE;
                }

                $output->writeln('<error>Нет сохранённого состояния для продолжения (чекпоинт не найден).</error>');
                return Command::FAILURE;
            }

            if (!TodoListResumeHelper::applyHistoryRollback($agentCfg, $plan)) {
                $logger = $agentCfg->getLogger();
                if ($logger !== null) {
                    $logger->warning('Откат истории невозможен: в чекпоинте нет history_message_count (возможен дубликат сообщения при сбое).');
                }
            }

            $startFromTodoIndex = $plan->getStartFromTodoIndex();
        }

        if ($todoList !== null) {
            $todoList->setDefaultConfigurationAgent($agentCfg);
        }

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

        $history = null;
        $error = null;

        EventLoop::queue(static function () use ($todoList, $attachments, $startFromTodoIndex, $sessionParamsDto, &$history, &$error): void {
            try {
                $history = $todoList->execute(
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

        if ($error !== null) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');
            $output->writeln('<error>' .  $error->getFile() . ' ' . $error->getLine() . '</error>');
            $output->writeln('<error>' .  $error->getTraceAsString() . '</error>');
            return Command::FAILURE;
        }

        /**
         * @var ChatHistoryInterface $history
         */

        $lastMessage = $history->getLastMessage();
        if ($lastMessage === false) {
            $output->writeln('<error>Нет ответа в истории чата.</error>');
            return Command::FAILURE;
        }

        /**
         * @var Message $lastMessage
         */
        $content = $lastMessage->getContent();

        $output->writeln(
            ConsoleHelper::formatOut($content, $agentCfg->getSessionKey(), $formatOut)
        );

        return Command::SUCCESS;
    }
}
