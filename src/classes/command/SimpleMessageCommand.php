<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\helpers\ConsoleHelper;
use app\modules\neuron\helpers\AttachmentHelper;
use NeuronAI\Chat\Enums\MessageRole;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Консольная команда отправки одного сообщения агенту и вывода ответа LLM.
 *
 * Передаёт указанное сообщение в LLM, сконфигурированную для выбранного агента
 * (см. {@see ConfigurationAgent}), и выводит в stdout текст ответа ассистента
 * и текущий {@see sessionKey}, чтобы сессию можно было продолжить следующим
 * вызовом с опцией {@see --session_id}.
 *
 * Исполнение реализовано через {@see TodoList} с одним заданием: сообщение
 * пользователя передаётся как тело списка заданий, после чего вызывается
 * {@see TodoList::executeFromAgent()}. Ожидание асинхронного результата
 * выполняется через {@see \Revolt\EventLoop}.
 *
 * Примеры вызова:
 *   php bin/console simplemessage --agent default --message "Привет!"
 *   php bin/console simplemessage --agent default --message "Продолжи" --session_id 20250301-143022-123456
 */
class SimpleMessageCommand extends AbstractAgentCommand
{
    /** Имя команды в консоли (например, php bin/console simplemessage). */
    protected static $defaultName = 'simplemessage';

    /**
     * Настраивает команду: описание и опции.
     *
     * Опции:
     * - agent   — имя агента (файл в agents/ без расширения), обязательно.
     * - message — текст сообщения пользователя, обязательно.
     * - session_id — необязательный ключ сессии для продолжения диалога;
     *   должен существовать в хранилище сессий, иначе выводится ошибка.
     * - file/-f — пути к файлам, которые будут прикреплены к запросу (можно указывать несколько раз).
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Отправляет сообщение агенту и выводит ответ с sessionKey для продолжения сессии')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Имя агента LLM (например, default)')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Текст сообщения')
            ->addOption('session_id', null, InputOption::VALUE_OPTIONAL, 'Ключ сессии для продолжения (формат buildSessionKey)')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Формат вывода. Доступно: md, txt, json', 'md')
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Путь к файлу для прикрепления (можно указать несколько раз)'
            );
    }

    /**
     * Выполняет команду: валидация опций, получение агента, отправка сообщения, вывод ответа.
     *
     * Последовательность:
     * 1. Проверка обязательных опций agent и message.
     * 2. Получение конфигурации агента через {@see ConfigurationApp::getAgent()}.
     * 3. При переданном session_id — проверка формата и существования сессии,
     *    затем установка ключа на конфиге агента.
     * 4. Создание TodoList с одним заданием (текст сообщения) и вызов
     *    executeFromAgent() в очереди событийного цикла с ожиданием Future.
     * 5. Вывод последнего сообщения из истории чата и sessionKey.
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
        $agentName   = $input->getOption('agent');
        $messageText = $input->getOption('message');
        $sessionId   = $input->getOption('session_id');
        $formatOut   = $input->getOption('format');
        $fileOptions = $input->getOption('file');

        // Проверка обязательных опций
        if ($agentName === null || $agentName === '') {
            $output->writeln('<error>Не указан агент. Используйте --agent.</error>');
            return Command::FAILURE;
        }

        if ($messageText === null || $messageText === '') {
            $output->writeln('<error>Не указано сообщение. Используйте --message.</error>');
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

        // Получение конфигурации приложения и конфига агента по имени
        $configApp = ConfigurationApp::getInstance();

        // Если передан session_id — проверяем формат и существование сессии, затем подставляем ключ
        if ($sessionId !== null && $sessionId !== '') {
            if (!ConfigurationApp::isValidSessionKey($sessionId)) {
                $output->writeln('<error>Неверный формат session_id. Ожидается формат Ymd-His-u (например, 20250301-143022-123456).</error>');
                return Command::FAILURE;
            }

            if (!ConfigurationApp::getInstance()->sessionExists($sessionId, $agentName)) {
                $output->writeln(sprintf('<error>Сессия с session_id "%s" для агента "%s" не найдена.</error>', $sessionId, $agentName));
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
        
        // проверим а завершено ли предыдущее сообщение
        $runStateDto = $agentCfg->getExistRunStateDto();
        if ($runStateDto) {
            $output->writeln(
                sprintf(
                    '<error>В сессии обнаружено незавершённое выполнение списка "%s".</error>',
                    $runStateDto->getTodolistName()
                )
            );
            return Command::FAILURE;
        }

        // Если передан session_id — проверяем формат и существование сессии, затем подставляем ключ
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

            // если задана сессия, то проверим а завершено ли предыдущее сообщение
            $runStateDto = $agentCfg->getExistRunStateDto();
            if ($runStateDto) {
                $output->writeln(sprintf(
                    '<error>В сессии обнаружено незавершённое выполнение списка "%s".</error>',
                    $runStateDto->getTodolistName()
                ));
                return Command::FAILURE;
            }
        }

        // Список из одного задания (текст сообщения) и producer навыков для возможных skills в todo
        $todoList = new TodoList($messageText, 'inline_message');
        $skillProducer = $configApp->getSkillProducer();

        // Запуск асинхронного выполнения в очереди событийного цикла и ожидание результата
        $history = null;
        $error = null;

        EventLoop::queue(static function () use ($todoList, $agentCfg, $skillProducer, $attachments, &$history, &$error): void {
            try {
                $history = $todoList->executeFromAgent(
                    $agentCfg,
                    MessageRole::USER,
                    $attachments,
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

        // Извлечение последнего сообщения (ответ ассистента) и вывод содержимого
        $lastMessage = $history->getLastMessage();
        if ($lastMessage === false) {
            $output->writeln('<error>Нет ответа в истории чата.</error>');
            return Command::FAILURE;
        }
        $content = $lastMessage->getContent();

        $output->writeln(
            ConsoleHelper::formatOut($content, $agentCfg->getSessionKey(), $formatOut)
        );

        return Command::SUCCESS;
    }

}
