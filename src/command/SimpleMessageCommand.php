<?php

declare(strict_types=1);

namespace app\modules\neuron\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\console\ConsoleServiceMessagesDto;
use app\modules\neuron\classes\dto\console\OutputDto;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\helpers\AttachmentHelper;
use app\modules\neuron\helpers\ConsoleHelper;
use NeuronAI\Chat\Enums\MessageRole;
use Revolt\EventLoop;
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
 * {@see TodoList::execute()}. Ожидание асинхронного результата
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
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Отправляет сообщение агенту и выводит ответ с sessionKey для продолжения сессии')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Имя агента LLM (например, default)')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Текст сообщения')
            ->addOption('session_id', null, InputOption::VALUE_OPTIONAL, 'Ключ сессии для продолжения (формат buildSessionKey)')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Формат вывода. Доступно: md, txt, json', 'md')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Продолжить выполнение с последнего чекпоинта')
            ->addOption('abort', null, InputOption::VALUE_NONE, 'Сбросить состояние незавершённого run для сессии')
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
     * @return int Command::SUCCESS или Command::FAILURE.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName   = (string) ($input->getOption('agent') ?? '');
        $messageText = (string) ($input->getOption('message') ?? '');
        $sessionId   = (string) ($input->getOption('session_id') ?? '');
        $fileOptions = $input->getOption('file');
        $resume      = (bool) $input->getOption('resume');
        $abort       = (bool) $input->getOption('abort');

        $formatResolved = ConsoleHelper::resolveFormat($input->getOption('format'), 'md');
        if ($formatResolved instanceof OutputDto) {
            return $this->finish($output, $formatResolved, 'md');
        }
        $formatOut = $formatResolved;

        $service = new ConsoleServiceMessagesDto();

        if ($agentName === '') {
            return $this->finish($output, OutputDto::fromMissingAgentOption($sessionId), $formatOut);
        }

        if ($messageText === '') {
            return $this->finish($output, OutputDto::fromMissingMessageOption($sessionId), $formatOut);
        }

        $attachments = [];
        if (is_array($fileOptions) && $fileOptions !== []) {
            $buildResult = AttachmentHelper::buildAttachmentsFromPaths($fileOptions);
            if ($buildResult->isError()) {
                return $this->finish($output, OutputDto::fromError($buildResult->getErrorMessage(), $sessionId), $formatOut);
            }
            $attachments = $buildResult->getAttachments();
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

        // Установим логгеры событий (запись в файлы)
        $this->resolveFileLogger($configApp);

        $agentCfg = $configApp->getAgent($agentName);

        if ($agentCfg === null) {
            return $this->finish($output, OutputDto::fromAgentNotFound($agentName, $configApp->getSessionKey()), $formatOut);
        }

        $runStateDto = $agentCfg->getExistRunStateDto();
        if ($runStateDto) {
            if ($abort) {
                $agentCfg->abortRunState();
                $service->addPlain('Статус "выполняется список" убран');
            } elseif ($resume) {
                $agentCfg->resumeRunState();
                $service->addPlain('Откат истории выполнен');
                $agentCfg->abortRunState();
                $service->addPlain('Статус "выполняется список" убран');
            } else {
                return $this->finish($output, OutputDto::fromUnfinishedRun(
                    $runStateDto->getTodolistName(),
                    $configApp->getSessionKey(),
                ), $formatOut);
            }
        }

        $todoList = new TodoList($messageText, 'inline_message', $configApp);
        $todoList->setDefaultConfigurationAgent($agentCfg);

        $error = null;

        EventLoop::queue(static function () use ($todoList, $attachments, &$error): void {
            try {
                $todoList->execute(
                    MessageRole::USER,
                    $attachments,
                    null
                )->await();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        EventLoop::run();

        $outDto = $error !== null
            ? OutputDto::fromException($error, $agentCfg)
            : OutputDto::fromAgent($agentCfg);

        return $this->finish($output, $outDto->withServiceMessages($service), $formatOut);
    }
}
