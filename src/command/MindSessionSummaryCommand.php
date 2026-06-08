<?php

declare(strict_types=1);

namespace app\modules\neuron\command;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\console\ConsoleServiceMessagesDto;
use app\modules\neuron\classes\dto\console\OutputDto;
use app\modules\neuron\helpers\ConsoleHelper;
use app\modules\neuron\interfaces\MindSessionSummaryRefresherInterface;
use app\modules\neuron\mind\dto\config\MindConfigDto;
use app\modules\neuron\mind\helpers\MindSessionSummaryCliConfigHelper;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\services\MindSessionSummaryService;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\UserMindStorage;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Консольная команда принудительного пересчёта LLM-summary сессии в `.mind`.
 *
 * Не требует блока `mind` в config приложения: агент-суммаризатор и параметры summary
 * задаются в CLI. Блок `mind` в config используется только автоматическим подписчиком.
 *
 * Примеры:
 *
 * <code>
 * php bin/console mind:summary --session_id 20250301-143022-123456-0 --agent my_summarizer_agent
 * php bin/console mind:summary --session_id 20250301-143022-123456-0 --agent my_summarizer_agent \
 *   --max-summary-chars 400 --transcript-ratio 0.3
 * </code>
 */
class MindSessionSummaryCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'mind:summary';

    protected function configure(): void
    {
        $this
            ->setDescription('Пересчитывает LLM-summary сессии в долговременной памяти .mind')
            ->addOption(
                'session_id',
                null,
                InputOption::VALUE_REQUIRED,
                'Ключ сессии (формат buildSessionKey)',
            )
            ->addOption(
                'agent',
                null,
                InputOption::VALUE_REQUIRED,
                'Имя агента-суммаризатора (agents/*.php, например my_summarizer_agent)',
            )
            ->addOption(
                'max-summary-chars',
                null,
                InputOption::VALUE_OPTIONAL,
                'Максимальная длина summary в индексе (UTF-8, не меньше 50)',
            )
            ->addOption(
                'transcript-ratio',
                null,
                InputOption::VALUE_OPTIONAL,
                'Доля контекстного окна агента под транскрипт (0.05–0.5)',
            )
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Формат вывода. Доступно: md, txt, json', 'md');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = trim((string) ($input->getOption('session_id') ?? ''));
        $agentName = trim((string) ($input->getOption('agent') ?? ''));

        $formatResolved = ConsoleHelper::resolveFormat($input->getOption('format'), 'md');
        if ($formatResolved instanceof OutputDto) {
            return $this->finish($output, $formatResolved, 'md');
        }
        $formatOut = $formatResolved;

        $service = new ConsoleServiceMessagesDto();

        if ($sessionId === '') {
            return $this->finish($output, OutputDto::fromError('Не указан --session_id.'), $formatOut);
        }

        if ($agentName === '') {
            return $this->finish($output, OutputDto::fromError('Не указан --agent (имя агента-суммаризатора).', $sessionId), $formatOut);
        }

        if (MindSummarySessionKeyHelper::isSummarySession($sessionId)) {
            return $this->finish($output, OutputDto::fromError(
                'Нельзя суммаризировать служебную сессию mind-summary. Укажите ключ основной сессии.',
                $sessionId,
            ), $formatOut);
        }

        if (!ConfigurationApp::isValidSessionKey($sessionId)) {
            return $this->finish($output, OutputDto::fromInvalidSessionKey($sessionId, '--session_id'), $formatOut);
        }

        try {
            $summaryConfig = MindSessionSummaryCliConfigHelper::fromInput($input, $agentName);
        } catch (InvalidArgumentException $e) {
            return $this->finish($output, OutputDto::fromError($e->getMessage(), $sessionId), $formatOut);
        }

        $app = ConfigurationApp::getInstance();
        $app->setSessionKey($sessionId);
        $this->resolveFileLogger($app);

        $summarizer = $this->resolveSummarizerAgent($app, $agentName);
        if ($summarizer === null) {
            return $this->finish($output, OutputDto::fromSummarizerAgentNotFound($agentName, $sessionId), $formatOut);
        }

        $effective = new MindConfigDto(null, $summaryConfig);

        $paths = new MindPaths($app->getMindDir(), $app->getUserId());
        $mind = new UserMindStorage($paths);
        $meta = $mind->getSessionsIndex()->get($sessionId);
        if ($meta === null) {
            return $this->finish($output, OutputDto::fromError(
                sprintf('Сессия "%s" отсутствует в индексе .mind (sessions.md).', $sessionId),
                $sessionId,
            ), $formatOut);
        }

        if ($meta->getMessageCount() === 0) {
            return $this->finish($output, OutputDto::fromError(
                sprintf('В сессии "%s" нет сообщений в .mind (messageCount=0).', $sessionId),
                $sessionId,
            ), $formatOut);
        }

        $service
            ->addInfo(sprintf('SessionKey: %s', $sessionId))
            ->addInfo(sprintf('Агент-суммаризатор: %s', $agentName))
            ->addInfo(sprintf('Сообщений в .mind: %d', $meta->getMessageCount()));

        $summaryRefresher = $this->createSummaryRefresher($app, $effective);
        $updated = $mind->refreshSessionSummary($app, $sessionId, $summaryRefresher, $effective);

        $metaAfter = $mind->getSessionsIndex()->get($sessionId);
        $summaryAfter = $metaAfter?->getSummary() ?? '';

        if ($summaryAfter === '') {
            return $this->finish($output, OutputDto::fromError(
                'Summary не получен (проверьте агента-суммаризатора и логи).',
                $sessionId,
            ), $formatOut);
        }

        if ($updated) {
            $service->addInfo('Summary обновлён.');
        } else {
            $service->addComment('Summary не изменился (уже актуален).');
        }

        return $this->finish(
            $output,
            OutputDto::fromResponse($summaryAfter, $sessionId)->withServiceMessages($service),
            $formatOut,
        );
    }

    /**
     * Загружает шаблон агента-суммаризатора по имени (переопределяется в тестах).
     */
    protected function resolveSummarizerAgent(ConfigurationApp $app, string $agentName): ?ConfigurationAgent
    {
        return $app->getAgent($agentName);
    }

    /**
     * Создаёт сервис суммаризации (переопределяется в тестах).
     */
    protected function createSummaryRefresher(
        ConfigurationApp $app,
        MindConfigDto $effective,
    ): MindSessionSummaryRefresherInterface {
        return MindSessionSummaryService::fromMindConfig($effective, $app);
    }
}
