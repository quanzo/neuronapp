<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\trimmers;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;

use function array_merge;
use function array_slice;
use function count;
use function max;
use function min;

/**
 * Триммер истории чата в стиле CCL Code: microcompact + LLM-summary головы.
 *
 * Алгоритм (упрощённо):
 * 1) Если история умещается в контекст — вернуть как есть.
 * 2) Microcompact: «очистить» старые tool-result (заменить payload результата на маркер),
 *    оставляя последние N tool-result без изменений.
 * 3) Если история всё ещё не влезает:
 *    - выбрать tail (хвост) по токенам;
 *    - свернуть head (голову) в одно summary-сообщение через {@see HistoryHeadSummarizerInterface};
 *    - вернуть [summary, tail...] и при необходимости жёстко урезать tail через {@see FluidContextWindowTrimmer}.
 *
 * Особенности:
 * - не разрывает пары {@see ToolCallMessage}/{@see ToolResultMessage};
 * - summary создаётся как сообщение с ролью DEVELOPER;
 * - для подсчёта токенов использует {@see TokenCounter}.
 *
 * Пример использования (через ConfigurationAgent):
 *
 * <code>
 * $summarizer = new ConfigurationAgentHistoryHeadSummarizer($agentCfg);
 * $trimmer = (new CclCodeHistoryTrimmer(new TokenCounter(), $summarizer))
 *     ->withTailRatio(0.6)
 *     ->withKeepRecentToolResults(8);
 * </code>
 */
final class CclCodeHistoryTrimmer implements HistoryTrimmerInterface
{
    /**
     * Итоговое количество токенов после последнего trim().
     */
    private int $totalTokens = 0;

    /**
     * Доля окна под tail (хвост), который сохраняется без изменений.
     */
    private float $tailRatio = 0.6;

    /**
     * Сколько последних tool-result оставлять без microcompact.
     */
    private int $keepRecentToolResults = 10;

    /**
     * Маркер, которым заменяется результат старых tool-result.
     */
    private string $clearedToolResultMarker = '[Old tool result content cleared]';

    public function __construct(
        private readonly TokenCounter $tokenCounter = new TokenCounter(),
        private readonly ?HistoryHeadSummarizerInterface $headSummarizer = null,
    ) {
    }

    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    /**
     * Устанавливает долю окна, выделяемую под tail.
     */
    public function withTailRatio(float $tailRatio): self
    {
        $this->tailRatio = min(0.95, max(0.05, $tailRatio));
        return $this;
    }

    /**
     * Устанавливает число последних tool-result, которые не microcompact-ятся.
     */
    public function withKeepRecentToolResults(int $count): self
    {
        $this->keepRecentToolResults = max(0, $count);
        return $this;
    }

    /**
     * Устанавливает маркер очищенного tool-result.
     */
    public function withClearedToolResultMarker(string $marker): self
    {
        $this->clearedToolResultMarker = $marker !== '' ? $marker : $this->clearedToolResultMarker;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function trim(array $messages, int $contextWindow): array
    {
        if ($messages === []) {
            $this->totalTokens = 0;
            return [];
        }

        // Сначала оцениваем размер как есть.
        $total = $this->computeTotalTokens($messages);
        $this->totalTokens = $total;
        if ($contextWindow <= 0 || $total <= $contextWindow) {
            return $messages;
        }

        // Шаг 1: microcompact старых tool-result.
        $messages = $this->microcompactToolResults($messages);
        $this->totalTokens = $this->computeTotalTokens($messages);
        if ($this->totalTokens <= $contextWindow) {
            return $messages;
        }

        // Шаг 2: если суммаризатор не задан — жёстко урезаем tail как fallback.
        if ($this->headSummarizer === null) {
            $window = (new FluidContextWindowTrimmer($this->tokenCounter))->trim($messages, $contextWindow);
            $this->totalTokens = $this->computeTotalTokens($window);
            return $window;
        }

        // Шаг 3: выбираем tail + строим summary для головы.
        $tailStart = $this->selectTailStartIndex($messages, $contextWindow);
        $head = array_slice($messages, 0, $tailStart);
        $tail = array_slice($messages, $tailStart);

        // Защита: не теряем последнюю пару ToolCall/ToolResult — включаем её в tail.
        for ($i = count($messages) - 2; $i >= 0; $i--) {
            if ($messages[$i] instanceof ToolCallMessage && $messages[$i + 1] instanceof ToolResultMessage) {
                if ($i < $tailStart) {
                    $head = array_slice($messages, 0, $i);
                    $tail = array_slice($messages, $i);
                }
                break;
            }
        }

        $tail = $this->ensureValidMessageSequence($tail);

        $summary = $head !== [] ? $this->headSummarizer->summarize($head, $contextWindow) : null;
        $candidate = $summary instanceof Message ? array_merge([$summary], $tail) : $tail;

        $this->totalTokens = $this->computeTotalTokens($candidate);
        if ($this->totalTokens <= $contextWindow) {
            return $candidate;
        }

        // Жёсткий fallback: строим окно от хвоста и добавляем summary только если помещается.
        $tailWindow = (new FluidContextWindowTrimmer($this->tokenCounter))->trim($tail, $contextWindow);
        if ($summary instanceof Message) {
            $withSummary = array_merge([$summary], $tailWindow);
            if ($this->computeTotalTokens($withSummary) <= $contextWindow) {
                $tailWindow = $withSummary;
            }
        }

        $this->totalTokens = $this->computeTotalTokens($tailWindow);
        return $tailWindow;
    }

    /**
     * Оценивает общее количество токенов в массиве сообщений.
     *
     * @param Message[] $messages
     */
    private function computeTotalTokens(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            $total += $this->tokenCounter->count($message);
        }
        return $total;
    }

    /**
     * Microcompact: очищает tool-result, кроме последних N.
     *
     * @param Message[] $messages
     *
     * @return Message[]
     */
    private function microcompactToolResults(array $messages): array
    {
        if ($this->keepRecentToolResults <= 0) {
            return $this->clearToolResultsBeforeIndex($messages, count($messages));
        }

        $toolResultIndexes = [];
        foreach ($messages as $idx => $message) {
            if ($message instanceof ToolResultMessage) {
                $toolResultIndexes[] = $idx;
            }
        }

        $countToolResults = count($toolResultIndexes);
        if ($countToolResults <= $this->keepRecentToolResults) {
            return $messages;
        }

        $cut = $countToolResults - $this->keepRecentToolResults;
        $lastIndexToClear = $toolResultIndexes[$cut - 1] ?? null;
        if ($lastIndexToClear === null) {
            return $messages;
        }

        return $this->clearToolResultsBeforeIndex($messages, $lastIndexToClear + 1);
    }

    /**
     * Очищает tool-result в диапазоне [0..$endExclusive).
     *
     * @param Message[] $messages
     *
     * @return Message[]
     */
    private function clearToolResultsBeforeIndex(array $messages, int $endExclusive): array
    {
        $endExclusive = max(0, min(count($messages), $endExclusive));

        for ($i = 0; $i < $endExclusive; $i++) {
            $msg = $messages[$i] ?? null;
            if (!$msg instanceof ToolResultMessage) {
                continue;
            }

            foreach ($msg->getTools() as $tool) {
                $this->setToolResultIfPossible($tool, $this->clearedToolResultMarker);
            }
        }

        return $messages;
    }

    /**
     * Пытается установить результат инструмента, не полагаясь на наличие метода в интерфейсе.
     */
    private function setToolResultIfPossible(ToolInterface $tool, string $result): void
    {
        if (method_exists($tool, 'setResult')) {
            /** @var mixed $toolObj */
            $toolObj = $tool;
            $toolObj->setResult($result);
        }
    }

    /**
     * Выбирает индекс начала tail, который нужно сохранить целиком.
     *
     * @param Message[] $messages
     */
    private function selectTailStartIndex(array $messages, int $contextWindow): int
    {
        $count = count($messages);
        if ($count === 0) {
            return 0;
        }

        $targetTokens = (int) max(1, $this->tailRatio * $contextWindow);
        $tailTokens = 0;
        $tailStart = $count;

        for ($i = $count - 1; $i >= 0; $i--) {
            $tailTokens += $this->tokenCounter->count($messages[$i]);
            $tailStart = $i;

            // Не разрываем ToolResult, если перед ним ToolCall.
            if ($messages[$i] instanceof ToolResultMessage && $i > 0 && $messages[$i - 1] instanceof ToolCallMessage) {
                $tailTokens += $this->tokenCounter->count($messages[$i - 1]);
                $tailStart = $i - 1;
                $i--;
            }

            if ($tailTokens >= $targetTokens) {
                break;
            }
        }

        // Финальная защита от разрыва пары ToolCall/ToolResult.
        if ($tailStart > 0 && $messages[$tailStart] instanceof ToolResultMessage && $messages[$tailStart - 1] instanceof ToolCallMessage) {
            $tailStart--;
        }

        return $tailStart;
    }

    /**
     * Обеспечивает валидную структуру диалога в окне (адаптация из HistoryTrimmer/HistoryCompactTrimmer).
     *
     * @param Message[] $messages
     *
     * @return Message[]
     */
    private function ensureValidMessageSequence(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        // Находим первое пользовательское сообщение (USER/DEVELOPER).
        $firstUserIndex = null;
        foreach ($messages as $index => $message) {
            $role = $message->getRole();
            if ($role === MessageRole::USER->value || $role === MessageRole::DEVELOPER->value) {
                $firstUserIndex = $index;
                break;
            }
        }

        if ($firstUserIndex === null) {
            // В крайнем случае: сохраняем последнюю пару ToolCall/ToolResult или последнее сообщение.
            for ($i = count($messages) - 2; $i >= 0; $i--) {
                if ($messages[$i] instanceof ToolCallMessage && $messages[$i + 1] instanceof ToolResultMessage) {
                    return [$messages[$i], $messages[$i + 1]];
                }
            }

            return [array_slice($messages, -1)[0]];
        }

        // Пытаемся сохранить последнюю пару ToolCall/ToolResult перед первым user-сообщением.
        $sliceStart = $firstUserIndex;
        if ($firstUserIndex > 0) {
            for ($i = $firstUserIndex; $i > 0; $i--) {
                if ($messages[$i] instanceof ToolResultMessage && $messages[$i - 1] instanceof ToolCallMessage) {
                    $sliceStart = $i - 1;
                    break;
                }
            }
        }

        if ($sliceStart > 0) {
            $messages = array_slice($messages, $sliceStart);
        }

        return $this->ensureValidAlternation($messages);
    }

    /**
     * Гарантирует корректное чередование ролей, не разрывая ToolCall/ToolResult.
     *
     * @param Message[] $messages
     *
     * @return Message[]
     */
    private function ensureValidAlternation(array $messages): array
    {
        $result = [];
        $userRoles = [MessageRole::USER->value, MessageRole::DEVELOPER->value];
        $assistantRoles = [MessageRole::ASSISTANT->value, MessageRole::MODEL->value];
        $expectingRoles = $userRoles;

        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage) {
                $result[] = $message;
                $expectingRoles = $userRoles;
                continue;
            }

            if ($message instanceof ToolResultMessage) {
                if ($result !== [] && $result[count($result) - 1] instanceof ToolCallMessage) {
                    $result[] = $message;
                    $expectingRoles = $assistantRoles;
                }
                continue;
            }

            $role = $message->getRole();
            if (in_array($role, $expectingRoles, true)) {
                $result[] = $message;
                $expectingRoles = ($expectingRoles === $userRoles) ? $assistantRoles : $userRoles;
            }
        }

        return $result;
    }
}
