<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\events\subscribers\RunLoggingSubscriber;
use app\modules\neuron\classes\logger\FileLogger;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\helpers\ConsoleHelper;
use app\modules\neuron\helpers\AttachmentHelper;
use NeuronAI\Chat\Enums\MessageRole;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AbstractAgentCommand extends Command
{
    /**
     * Установим в приложение логгер
     *
     * @param ConfigurationApp $appCfg
     * @return void
     */
    public function resolveFileLogger(ConfigurationApp $appCfg)
    {
        $appCfg = ConfigurationApp::getInstance();
        $logDir = $appCfg->getLogDir();
        $fn     = $appCfg->getSessionKey() . '.log';
        $logger = new FileLogger($logDir . DIRECTORY_SEPARATOR . $fn);
        $appCfg->setLogger($logger);
        RunLoggingSubscriber::register($logger);
    }
}
