<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\command\MindSessionSummaryCommand;
use app\modules\neuron\interfaces\MindSessionSummaryRefresherInterface;
use app\modules\neuron\mind\dto\config\MindConfigDto;

/**
 * Подкласс {@see MindSessionSummaryCommand} с подменой сервиса суммаризации для тестов.
 */
final class TestableMindSessionSummaryCommand extends MindSessionSummaryCommand
{
    public function __construct(
        private readonly MindSessionSummaryRefresherInterface $refresher,
        private readonly ?ConfigurationAgent $summarizerAgent = null,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function resolveSummarizerAgent(ConfigurationApp $app, string $agentName): ?ConfigurationAgent
    {
        if ($this->summarizerAgent !== null) {
            return $this->summarizerAgent;
        }

        return parent::resolveSummarizerAgent($app, $agentName);
    }

    /**
     * {@inheritdoc}
     */
    protected function createSummaryRefresher(
        ConfigurationApp $app,
        MindConfigDto $effective,
    ): MindSessionSummaryRefresherInterface {
        return $this->refresher;
    }
}
