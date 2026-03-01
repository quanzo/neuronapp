<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\ConfigurationAgent;
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
class SimpleMessageCommand extends Command
{
    /** Имя команды в консоли (например, php bin/console simplemessage). */
    protected static $defaultName = 'simplemessage';

    /**
     * Регулярное выражение для проверки формата sessionKey.
     *
     * Формат совпадает с {@see ConfigurationApp::buildSessionKey()}: Ymd-His-u
     * (дата 8 цифр, дефис, время 6 цифр, дефис, микросекунды).
     */
    private const SESSION_KEY_PATTERN = '/^\d{8}-\d{6}-\d+$/';

    /**
     * Настраивает команду: описание и опции.
     *
     * Опции:
     * - agent   — имя агента (файл в agents/ без расширения), обязательно.
     * - message — текст сообщения пользователя, обязательно.
     * - session_id — необязательный ключ сессии для продолжения диалога;
     *   должен существовать в хранилище сессий, иначе выводится ошибка.
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Отправляет сообщение агенту и выводит ответ с sessionKey для продолжения сессии')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Имя агента LLM (например, default)')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Текст сообщения')
            ->addOption('session_id', null, InputOption::VALUE_OPTIONAL, 'Ключ сессии для продолжения (формат buildSessionKey)');
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
        $agentName = $input->getOption('agent');
        $messageText = $input->getOption('message');
        $sessionId = $input->getOption('session_id');

        // Проверка обязательных опций
        if ($agentName === null || $agentName === '') {
            $output->writeln('<error>Не указан агент. Используйте --agent.</error>');
            return Command::FAILURE;
        }

        if ($messageText === null || $messageText === '') {
            $output->writeln('<error>Не указано сообщение. Используйте --message.</error>');
            return Command::FAILURE;
        }

        // Получение конфигурации приложения и конфига агента по имени
        $configApp = ConfigurationApp::getInstance();
        $agentCfg = $configApp->getAgent($agentName);

        if ($agentCfg === null) {
            $output->writeln(sprintf('<error>Агент "%s" не найден.</error>', $agentName));
            return Command::FAILURE;
        }

        // Если передан session_id — проверяем формат и существование сессии, затем подставляем ключ
        if ($sessionId !== null && $sessionId !== '') {
            if (preg_match(self::SESSION_KEY_PATTERN, $sessionId) !== 1) {
                $output->writeln('<error>Неверный формат session_id. Ожидается формат Ymd-His-u (например, 20250301-143022-123456).</error>');
                return Command::FAILURE;
            }

            if (!ConfigurationApp::sessionExists($sessionId, $agentName)) {
                $output->writeln(sprintf('<error>Сессия с session_id "%s" для агента "%s" не найдена.</error>', $sessionId, $agentName));
                return Command::FAILURE;
            }

            $agentCfg->setSessionKey($sessionId);
        }

        // Список из одного задания (текст сообщения) и producer навыков для возможных skills в todo
        $todoList = new TodoList($messageText);
        $skillProducer = $configApp->getSkillProducer();

        // Запуск асинхронного выполнения в очереди событийного цикла и ожидание результата
        $history = null;
        $error = null;

        EventLoop::queue(static function () use ($todoList, $agentCfg, $skillProducer, &$history, &$error): void {
            try {
                $history = $todoList->executeFromAgent(
                    $agentCfg,
                    MessageRole::USER,
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

        if (is_string($content)) {
            $output->writeln($content);
        } elseif (is_scalar($content)) {
            $output->writeln((string) $content);
        } else {
            $output->writeln(json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        }

        // Вывод sessionKey для использования в следующем вызове (--session_id)
        $output->writeln('');
        $output->writeln('sessionKey: ' . $agentCfg->getSessionKey());

        return Command::SUCCESS;
    }
}
