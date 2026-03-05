<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\helpers\ConsoleHelper;
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
 * {@see TodoList::executeFromAgent()}, выводит итоговый ответ агента и
 * {@see sessionKey} для продолжения сессии.
 *
 * Исполнитель — всегда агент из опции --agent; в файле TodoList агент не задаётся.
 *
 * Примеры вызова:
 *   php bin/console todolist --todolist code-review --agent default
 *   php bin/console todolist --todolist research-topic --agent default --session_id 20250301-143022-123456
 */
class TodolistCommand extends Command
{
    /** Имя команды в консоли. */
    protected static $defaultName = 'todolist';

    /**
     * Настраивает команду: описание и опции.
     *
     * Опции:
     * - todolist   — имя списка заданий (файл в todos/ без расширения), обязательно.
     * - agent      — имя агента LLM для исполнения, обязательно.
     * - session_id — необязательный ключ сессии для продолжения диалога.
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Выполняет список заданий TodoList через указанного агента и выводит ответ с sessionKey')
            ->addOption('todolist', null, InputOption::VALUE_REQUIRED, 'Имя списка заданий (например, code-review)')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Имя агента LLM для исполнения (например, default)')
            ->addOption('session_id', null, InputOption::VALUE_OPTIONAL, 'Ключ сессии для продолжения (формат buildSessionKey)');
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
        $agentName = $input->getOption('agent');
        $sessionId = $input->getOption('session_id');
        $formatOut = $input->getOption('format');

        if ($todolistName === null || $todolistName === '') {
            $output->writeln('<error>Не указан список заданий. Используйте --todolist.</error>');
            return Command::FAILURE;
        }

        if ($agentName === null || $agentName === '') {
            $output->writeln('<error>Не указан агент. Используйте --agent.</error>');
            return Command::FAILURE;
        }

        if ($formatOut === null || $formatOut === '') {
            $formatOut = 'md';
        }
        if (!in_array($formatOut, $arFormatAvailable)) {
            $output->writeln('<error>Формат вывода задан не корректно.</error>');
            return Command::FAILURE;
        }

        $configApp = ConfigurationApp::getInstance();
        $todoList = $configApp->getTodoList($todolistName);

        if ($todoList === null) {
            $output->writeln(sprintf('<error>TodoList "%s" не найден.</error>', $todolistName));
            return Command::FAILURE;
        }

        $agentCfg = $configApp->getAgent($agentName);

        if ($agentCfg === null) {
            $output->writeln(sprintf('<error>Агент "%s" не найден.</error>', $agentName));
            return Command::FAILURE;
        }

        if ($sessionId !== null && $sessionId !== '') {
            if (!ConfigurationApp::isValidSessionKey($sessionId)) {
                $output->writeln('<error>Неверный формат session_id. Ожидается формат Ymd-His-u (например, 20250301-143022-123456).</error>');
                return Command::FAILURE;
            }

            if (!ConfigurationApp::getInstance()->sessionExists($sessionId, $agentName)) {
                $output->writeln(sprintf('<error>Сессия с session_id "%s" для агента "%s" не найдена.</error>', $sessionId, $agentName));
                return Command::FAILURE;
            }

            $agentCfg->setSessionKey($sessionId);
        }

        $skillProducer = $configApp->getSkillProducer();

        $history = null;
        $error = null;

        EventLoop::queue(static function () use ($todoList, $agentCfg, $skillProducer, &$history, &$error): void {
            try {
                $history = $todoList->executeFromAgent(
                    $agentCfg,
                    MessageRole::USER,
                    [],
                    null,
                    $skillProducer
                )->await();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        EventLoop::run();

        if ($error !== null) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');
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
