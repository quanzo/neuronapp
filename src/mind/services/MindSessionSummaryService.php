<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\services;

use app\modules\neuron\interfaces\MindSessionSummaryRefresherInterface;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\neuron\trimmers\ConfigurationAgentHistoryHeadSummarizer;
use app\modules\neuron\classes\neuron\trimmers\FluidContextWindowTrimmer;
use app\modules\neuron\classes\neuron\trimmers\TokenCounter;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\mind\dto\config\MindConfigDto;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\storage\UserMindStorage;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * Сервис генерации краткого описания (summary) сессии для индекса `sessions.md`.
 *
 * Настройки — {@see MindConfigDto}; шаблон агента-суммаризатора резолвится при создании
 * через {@see self::fromMindConfig()}.
 *
 * Пример:
 *
 * <code>
 * $effective = $agent->resolveEffectiveMindConfig($app);
 * $service = MindSessionSummaryService::fromMindConfig($effective, $app);
 * $service->refreshSessionSummary($mind, $sessionKey);
 * </code>
 */
final class MindSessionSummaryService implements MindSessionSummaryRefresherInterface
{
    /**
     * @param MindConfigDto                  $mindConfig         Effective-конфиг mind.
     * @param ConfigurationAgent|null $summarizerTemplate Агент-суммаризатор или null.
     */
    private function __construct(
        private readonly MindConfigDto $mindConfig,
        private readonly ?ConfigurationAgent $summarizerTemplate,
    ) {
    }

    /**
     * Создаёт сервис: резолвит агента по имени из DTO через реестр приложения.
     */
    public static function fromMindConfig(MindConfigDto $mindConfig, ConfigurationApp $app): self
    {
        $agentName = $mindConfig->resolveSessionSummary()->resolveAgent();
        $template = $agentName !== '' ? $app->getAgent($agentName) : null;

        return new self($mindConfig, $template);
    }

    /**
     * Пересчитывает summary сессии и пишет его в `sessions.md`.
     *
     * Если агент не сконфигурирован или не найден — ничего не делает (summary остаётся пустым).
     */
    public function refreshSessionSummary(UserMindStorage $mind, string $sessionKey): void
    {
        if (MindSummarySessionKeyHelper::isSummarySession($sessionKey)) {
            return;
        }

        if ($this->summarizerTemplate === null) {
            return;
        }

        $summaryCfg = $this->mindConfig->resolveSessionSummary();

        $agent = $this->summarizerTemplate->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY);

        $meta = $mind->getSessionsIndex()->get($sessionKey);
        if ($meta === null) {
            return;
        }

        $contextWindow = (int) ($agent->contextWindow ?? 0);
        if ($contextWindow <= 0) {
            return;
        }

        $ratio = $summaryCfg->resolveTranscriptRatio();
        $transcriptWindow = (int) max(256, (int) ($ratio * $contextWindow));

        $session = $mind->openSession($sessionKey);
        $records = $session->readAll();
        if ($records === []) {
            return;
        }

        $messages = [];
        foreach ($records as $r) {
            $role = MessageRole::tryFrom($r->getRole()) ?? MessageRole::USER;
            $messages[] = new Message($role, $r->getBody());
        }

        $trimmer = new FluidContextWindowTrimmer(new TokenCounter());
        $windowMessages = $trimmer->trim($messages, $transcriptWindow);
        if ($windowMessages === []) {
            return;
        }

        $agent->setExcludeLongTermMind(true);
        $agent->setSessionKey(MindSummarySessionKeyHelper::forMainSession($sessionKey));

        $summarizer = new ConfigurationAgentHistoryHeadSummarizer($agent);
        $summaryMsg = $summarizer->summarize($windowMessages, $contextWindow);
        if ($summaryMsg === null) {
            return;
        }

        $summary = trim((string) ($summaryMsg->getContent() ?? ''));
        if ($summary === '') {
            return;
        }

        $summary = preg_replace('/\s+/u', ' ', $summary) ?? $summary;
        $summary = trim($summary);

        $maxChars = $summaryCfg->resolveMaxSummaryChars();

        if (mb_strlen($summary, 'UTF-8') > $maxChars) {
            $summary = mb_substr($summary, 0, $maxChars - 1, 'UTF-8') . '…';
        }

        $meta->setSummary($summary);
        $mind->getSessionsIndex()->upsert($meta);
    }
}
