<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\trimmers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function count;
use function implode;
use function is_string;
use function mb_substr;
use function trim;

/**
 * LLM-суммаризатор «головы» истории на базе существующего {@see ConfigurationAgent}.
 *
 * Реализация:
 * - создаёт клон конфигурации с пустой in-memory историей ({@see ChatHistoryCloneMode::RESET_EMPTY}),
 *   чтобы не загрязнять основную историю и не запускать рекурсивную компактизацию;
 * - отправляет в LLM один запрос с инструкциями для суммаризации и приложенным текстовым «транскриптом»
 *   head-сообщений;
 * - возвращает summary как одно сообщение с ролью DEVELOPER (для дальнейшей подстановки в историю).
 *
 * Пример:
 *
 * <code>
 * $summarizer = new ConfigurationAgentHistoryHeadSummarizer($agentCfg);
 * $summary = $summarizer->summarize($headMessages, $contextWindow);
 * </code>
 */
final class ConfigurationAgentHistoryHeadSummarizer implements HistoryHeadSummarizerInterface
{
    /**
     * Маркер, подставляемый вместо бинарного/медиа контента.
     */
    private string $imageMarker = '[image]';

    /**
     * Маркер, подставляемый вместо document/file контента.
     */
    private string $documentMarker = '[document]';

    /**
     * Ограничение на длину одной строки транскрипта (защита от слишком больших tool результатов).
     */
    private int $maxLineChars = 4_000;

    /**
     * Максимальная длина итогового summary в символах (грубая защита).
     */
    private int $maxSummaryChars = 8_000;

    public function __construct(
        private readonly ConfigurationAgent $agentCfg,
    ) {
    }

    /**
     * Устанавливает маркер изображения.
     */
    public function withImageMarker(string $marker): self
    {
        $this->imageMarker = $marker;
        return $this;
    }

    /**
     * Устанавливает маркер документа/файла.
     */
    public function withDocumentMarker(string $marker): self
    {
        $this->documentMarker = $marker;
        return $this;
    }

    /**
     * Устанавливает максимальную длину строки транскрипта.
     */
    public function withMaxLineChars(int $maxLineChars): self
    {
        $this->maxLineChars = max(256, $maxLineChars);
        return $this;
    }

    /**
     * Устанавливает максимальную длину summary.
     */
    public function withMaxSummaryChars(int $maxSummaryChars): self
    {
        $this->maxSummaryChars = max(512, $maxSummaryChars);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function summarize(array $headMessages, int $contextWindow): ?Message
    {
        if ($headMessages === [] || $contextWindow <= 0) {
            return null;
        }

        $transcript = $this->buildTranscript($headMessages);
        if (trim($transcript) === '') {
            return null;
        }

        $summaryText = $this->summarizeViaAgent($transcript);
        $summaryText = trim($summaryText);
        if ($summaryText === '') {
            return null;
        }

        if (mb_strlen($summaryText) > $this->maxSummaryChars) {
            $summaryText = mb_substr($summaryText, 0, $this->maxSummaryChars);
        }

        return new Message(MessageRole::DEVELOPER, $summaryText);
    }

    /**
     * Генерирует summary через клон {@see ConfigurationAgent}.
     */
    private function summarizeViaAgent(string $transcript): string
    {
        $clone = $this->agentCfg->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY);

        // На время суммаризации отключаем инструменты и делаем инструкцию максимально жёсткой.
        $clone->tools = [];
        $clone->toolMaxTries = 0;
        $clone->instructions = self::buildSummarizerInstructions();

        $userMsg = new Message(
            MessageRole::USER,
            "Сверни историю в summary.\n\nТранскрипт:\n" . $transcript
        );

        $response = $clone->sendMessage($userMsg);

        if ($response instanceof Message) {
            return (string) ($response->getContent() ?? '');
        }

        // На случай структурированных ответов/прочих типов — приводим к строке.
        if (is_string($response)) {
            return $response;
        }

        if (is_object($response) && method_exists($response, '__toString')) {
            return (string) $response;
        }

        return '';
    }

    /**
     * Строит текстовый транскрипт для head-сообщений.
     *
     * - медиа-блоки заменяются на текстовые маркеры;
     * - tool-call/tool-result представлены как строки, чтобы LLM могла учитывать их смысл,
     *   но не «тащила» полный payload.
     *
     * @param Message[] $messages
     */
    private function buildTranscript(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage) {
                $lines[] = '[tool_call] ' . $this->formatToolNames($message->getTools());
                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $lines[] = '[tool_result] ' . $this->formatToolResultsCompact($message->getTools());
                continue;
            }

            $role = $message->getRole();
            $content = $this->extractTextWithMediaMarkers($message);
            $content = trim($content);

            if ($content === '') {
                continue;
            }

            $lines[] = '[' . $role . '] ' . $this->capLine($content);
        }

        return implode("\n", $lines);
    }

    /**
     * Извлекает текст сообщений, подменяя медиа-контент маркерами.
     */
    private function extractTextWithMediaMarkers(Message $message): string
    {
        $parts = [];
        foreach ($message->getContentBlocks() as $block) {
            if ($block instanceof TextContent) {
                $parts[] = $block->getContent();
                continue;
            }
            if ($block instanceof ImageContent) {
                $parts[] = $this->imageMarker;
                continue;
            }
            if ($block instanceof FileContent) {
                $parts[] = $this->documentMarker;
                continue;
            }

            // Прочие блоки (audio/video/reasoning) считаем непечатаемыми в транскрипт.
        }

        return implode(' ', array_map(static fn(string $v): string => trim($v), $parts));
    }

    /**
     * Коротко форматирует список инструментов (только имена).
     *
     * @param ToolInterface[] $tools
     */
    private function formatToolNames(array $tools): string
    {
        $names = [];
        foreach ($tools as $tool) {
            $names[] = $tool->getName();
        }
        return implode(', ', $names);
    }

    /**
     * Форматирует результаты инструментов компактно (имя + обрезанный результат).
     *
     * @param ToolInterface[] $tools
     */
    private function formatToolResultsCompact(array $tools): string
    {
        $chunks = [];
        foreach ($tools as $tool) {
            $result = trim((string) $tool->getResult());
            if ($result === '') {
                $chunks[] = $tool->getName() . ': [empty]';
                continue;
            }

            $chunks[] = $tool->getName() . ': ' . $this->capLine($result);
        }

        return implode(' | ', $chunks);
    }

    private function capLine(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $this->maxLineChars) {
            return $text;
        }

        return mb_substr($text, 0, $this->maxLineChars - 1) . '…';
    }

    /**
     * Инструкция суммаризатора (system/developer).
     */
    private static function buildSummarizerInstructions(): string
    {
        return implode("\n", [
            'Ты — помощник, который суммаризирует историю чата для последующего продолжения работы.',
            '',
            'Правила:',
            '- Ответь ТОЛЬКО текстом (без вызовов инструментов).',
            '- Сфокусируйся на фактах: цель пользователя, ключевые решения, важные детали, состояние работы.',
            '- Если встречаются tool_call/tool_result — учти их смысл, но не переписывай большие выводы целиком.',
            '- Не добавляй выдуманных деталей.',
            '',
            'Формат:',
            '- Короткий заголовок: "Резюме контекста".',
            '- 5–15 буллетов по сути (что делали/решили/где остановились).',
        ]);
    }
}
