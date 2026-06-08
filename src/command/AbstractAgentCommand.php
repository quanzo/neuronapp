<?php

declare(strict_types=1);

namespace app\modules\neuron\command;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\console\OutputDto;
use app\modules\neuron\classes\dto\console\OutputExecutionTimingDto;
use app\modules\neuron\classes\dto\console\OutputExecutionTimingStartDto;
use app\modules\neuron\classes\events\subscribers\LlmInferenceLoggingSubscriber;
use app\modules\neuron\helpers\ConsoleHelper;
use app\modules\neuron\classes\events\subscribers\LongTermMindSubscriber;
use app\modules\neuron\classes\events\subscribers\OrchestratorLoggingSubscriber;
use app\modules\neuron\classes\events\subscribers\RunLoggingSubscriber;
use app\modules\neuron\classes\events\subscribers\SkillLoggingSubscriber;
use app\modules\neuron\classes\events\subscribers\TodoListLoggingSubscriber;
use app\modules\neuron\classes\events\subscribers\ToolLoggingSubscriber;
use app\modules\neuron\classes\logger\FileLogger;
use app\modules\neuron\helpers\StorageFileHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AbstractAgentCommand extends Command
{
    private ?OutputExecutionTimingStartDto $commandTimingStart = null;

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
        $fn     = StorageFileHelper::sessionLogFileName($appCfg->getSessionKey());
        $logger = new FileLogger($logDir . DIRECTORY_SEPARATOR . $fn);
        $appCfg->setLogger($logger);
        RunLoggingSubscriber::register($logger);
        SkillLoggingSubscriber::register($logger);
        TodoListLoggingSubscriber::register($logger);
        ToolLoggingSubscriber::register($logger);
        OrchestratorLoggingSubscriber::register($logger);
        LlmInferenceLoggingSubscriber::register($logger);
        LongTermMindSubscriber::register();
    }

    /**
     * Запускает команду и фиксирует метки времени старта.
     *
     * @param InputInterface  $input  Входные аргументы и опции.
     * @param OutputInterface $output Поток вывода.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->commandTimingStart = OutputExecutionTimingStartDto::captureNow();

        return parent::run($input, $output);
    }

    /**
     * Записывает унифицированный результат команды и возвращает exit code.
     *
     * @param OutputInterface $output Консольный вывод.
     * @param OutputDto       $dto    DTO результата.
     * @param string          $format Формат: md, txt или json.
     */
    protected function finish(OutputInterface $output, OutputDto $dto, string $format): int
    {
        if ($this->commandTimingStart !== null) {
            $dto = $dto->withExecutionTiming(
                OutputExecutionTimingDto::fromStartSnapshot($this->commandTimingStart)
            );
        }

        return ConsoleHelper::writeResult($output, $dto, $format);
    }
}
