<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\services;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\neuron\trimmers\ConfigurationAgentHistoryHeadSummarizer;
use app\modules\neuron\classes\neuron\trimmers\FluidContextWindowTrimmer;
use app\modules\neuron\classes\neuron\trimmers\TokenCounter;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\mind\storage\UserMindStorage;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * Сервис генерации краткого описания (summary) сессии для индекса `sessions.md`.
 *
 * Требования
 * ----------
 * - использует LLM-агента из инфраструктуры проекта;
 * - учитывает ограниченность контекстного окна (тримминг по токенам);
 * - если агент не указан в конфиге mind — summary оставляем пустым.
 *
 * Конфигурация (config.jsonc)
 * ---------------------------
 * - `mind.session_summary.agent` (string|null): имя агента-суммаризатора.
 * - `mind.session_summary.max_summary_chars` (int, default 300): ограничение индекса.
 * - `mind.session_summary.transcript_ratio` (float, default 0.25): доля окна, выделяемая под транскрипт.
 */
final class MindSessionSummaryService
{
    /**
     * Минимальная доля окна, которую имеет смысл выделять под транскрипт.
     */
    private const float MIN_RATIO = 0.05;

    /**
     * Максимальная доля окна для транскрипта (чтобы оставить место под инструкции и ответ).
     */
    private const float MAX_RATIO = 0.5;

    /**
     * Пересчитывает summary сессии и пишет его в `sessions.md`.
     *
     * Если агент не сконфигурирован или не найден — ничего не делает (summary остаётся пустым).
     */
    public function refreshSessionSummary(ConfigurationApp $app, UserMindStorage $mind, string $sessionKey): void
    {
        $agentName = (string) $app->get('mind.session_summary.agent', '');
        $agentName = trim($agentName);
        if ($agentName === '') {
            return;
        }

        $agent0 = $app->getAgent($agentName);
        if ($agent0 === null) {
            return;
        }

        // клонируем класс чтобы он был чистым без истории
        $agent = $agent0->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY);

        $meta = $mind->getSessionsIndex()->get($sessionKey);
        if ($meta === null) {
            return;
        }

        $contextWindow = (int) ($agent->contextWindow ?? 0);
        if ($contextWindow <= 0) {
            return;
        }

        $ratio = (float) $app->get('mind.session_summary.transcript_ratio', 0.25);
        if ($ratio < self::MIN_RATIO) {
            $ratio = self::MIN_RATIO;
        }
        if ($ratio > self::MAX_RATIO) {
            $ratio = self::MAX_RATIO;
        }

        $transcriptWindow = (int) max(256, (int) ($ratio * $contextWindow));

        $session = $mind->openSession($sessionKey);
        $records = $session->readAll();
        if ($records === []) {
            return;
        }

        // Переводим в NeuronAI Message[] и триммим под окно.
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

        // Важно: суммаризатор запускает отдельный LLM-запрос. Его нужно исключить из `.mind`.
        $agent->setExcludeLongTermMind(true);

        $summarizer = new ConfigurationAgentHistoryHeadSummarizer($agent);
        $summaryMsg = $summarizer->summarize($windowMessages, $contextWindow);
        if ($summaryMsg === null) {
            return;
        }

        $summary = trim((string) ($summaryMsg->getContent() ?? ''));
        if ($summary === '') {
            return;
        }

        // В `sessions.md` держим компактную однострочную версию.
        $summary = preg_replace('/\s+/u', ' ', $summary) ?? $summary;
        $summary = trim($summary);

        $maxChars = (int) $app->get('mind.session_summary.max_summary_chars', 300);
        if ($maxChars < 50) {
            $maxChars = 50;
        }

        if (mb_strlen($summary, 'UTF-8') > $maxChars) {
            $summary = mb_substr($summary, 0, $maxChars - 1, 'UTF-8') . '…';
        }

        $meta->setSummary($summary);
        $mind->getSessionsIndex()->upsert($meta);
    }
}
