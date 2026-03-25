<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\events\subscribers\OrchestratorLoggingSubscriber;
use app\modules\neuron\classes\events\subscribers\RunLoggingSubscriber;
use app\modules\neuron\classes\events\subscribers\SkillLoggingSubscriber;
use app\modules\neuron\classes\events\subscribers\TodoListLoggingSubscriber;
use app\modules\neuron\classes\events\subscribers\ToolLoggingSubscriber;
use app\modules\neuron\classes\logger\FileLogger;
use Symfony\Component\Console\Command\Command;

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
        SkillLoggingSubscriber::register($logger);
        TodoListLoggingSubscriber::register($logger);
        ToolLoggingSubscriber::register($logger);
        OrchestratorLoggingSubscriber::register($logger);
    }
}
