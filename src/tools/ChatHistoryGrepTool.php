<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\ChatHistoryGrepMatchDto;
use app\modules\neuron\classes\dto\tools\ChatHistoryGrepResultDto;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use app\modules\neuron\helpers\ChatHistoryToolMessageHelper;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function count;
use function explode;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use function preg_last_error_msg;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function str_contains;

use const JSON_UNESCAPED_UNICODE;
use const PREG_OFFSET_CAPTURE;

/**
 * Инструмент поиска строки или регулярного выражения в полной истории сообщений.
 *
 * Назначение: дать LLM быстрый способ найти нужный фрагмент в истории без
 * полного перебора через chat_history.message по индексам.
 *
 * Особенности:
 * - поддерживает входной паттерн как regex (с разделителями), либо как простой текст;
 * - поиск выполняется построчно внутри каждого сообщения (по "\n");
 * - возвращает ограниченный список совпадений (maxMatches) с указанием индекса сообщения,
 *   роли и номера строки.
 */
final class ChatHistoryGrepTool extends ATool
{
    /** @var int Максимальное число совпадений в ответе */
    private int $defaultMaxMatches = 50;

    /** @var int Максимальная длина строки (lineContent) в результате */
    private int $maxLineLength = 500;

    /** @var int Максимальная длина matchText в результате */
    private int $maxMatchLength = 200;

    public function __construct(
        string $name = 'chat_history.grep',
        string $description = 'Поиск строки/regex в полной истории сообщений. Возвращает список совпадений: индекс сообщения, роль, номер строки и фрагмент.',
    ) {
        parent::__construct(name: $name, description: $description);
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name       : 'pattern',
                type       : PropertyType::STRING,
                description: 'Регулярное выражение (с разделителями, например "/error/i") или простой текст для поиска.',
                required   : true,
            ),
            ToolProperty::make(
                name       : 'caseInsensitive',
                type       : PropertyType::BOOLEAN,
                description: 'Игнорировать регистр (работает для текстового поиска и для некоторых regex без модификатора i).',
                required   : false,
            ),
            ToolProperty::make(
                name       : 'includeToolMessages',
                type       : PropertyType::BOOLEAN,
                description: 'Искать ли в tool-call/tool-result сообщениях истории.',
                required   : false,
            ),
            ToolProperty::make(
                name       : 'maxMatches',
                type       : PropertyType::INTEGER,
                description: 'Максимальное количество совпадений в ответе (по умолчанию 50).',
                required   : false,
            ),
        ];
    }

    /**
     * Выполняет поиск по полной истории сообщений.
     *
     * @param string   $pattern            Regex или текст.
     * @param bool     $caseInsensitive    Игнорировать регистр.
     * @param bool     $includeToolMessages Искать ли в tool-call/tool-result сообщениях.
     * @param int|null $maxMatches         Лимит результатов.
     *
     * @return string JSON
     */
    public function __invoke(
        string $pattern,
        bool $caseInsensitive = false,
        bool $includeToolMessages = true,
        ?int $maxMatches = null,
    ): string {
        $regex = $this->buildRegex($pattern, $caseInsensitive);
        if ($regex === null) {
            return json_encode([
                'error' => "Некорректный паттерн: '{$pattern}'. " . preg_last_error_msg(),
            ], JSON_UNESCAPED_UNICODE);
        }

        $agentCfg = $this->getAgentCfg();
        $history = $agentCfg?->getChatHistory();

        /** @var Message[] $messages */
        $messages = [];
        if ($history instanceof AbstractFullChatHistory) {
            $messages = $history->getFullMessages();
        } elseif ($history !== null) {
            $messages = $history->getMessages();
        }

        $limit = $maxMatches ?? $this->defaultMaxMatches;
        if ($limit < 1) {
            $limit = 1;
        }

        $matches = [];
        $totalMatches = 0;
        $messagesSearched = 0;
        $truncated = false;

        $countMessages = count($messages);
        for ($i = 0; $i < $countMessages; $i++) {
            if (count($matches) >= $limit) {
                $truncated = true;
                break;
            }

            $msg = $messages[$i];
            $isTool = $msg instanceof ToolCallMessage || $msg instanceof ToolResultMessage;
            if (!$includeToolMessages && $isTool) {
                continue;
            }

            $messagesSearched++;

            $content = (string) ($msg->getContent() ?? '');
            if ($content === '') {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $lineIdx => $line) {
                if ($line === '') {
                    continue;
                }

                if (count($matches) >= $limit) {
                    $truncated = true;
                    break 2;
                }

                $lineMatches = [];
                $result = @preg_match_all($regex, $line, $lineMatches, PREG_OFFSET_CAPTURE);
                if ($result === false || $result === 0) {
                    continue;
                }

                $toolSig = $isTool ? ChatHistoryToolMessageHelper::extractToolSignature($msg) : null;

                foreach ($lineMatches[0] as $matchData) {
                    $totalMatches++;
                    if (count($matches) >= $limit) {
                        $truncated = true;
                        break 3;
                    }

                    $matchText = (string) $matchData[0];
                    $matches[] = new ChatHistoryGrepMatchDto(
                        index: $i,
                        role: (string) $msg->getRole(),
                        lineNumber: $lineIdx + 1,
                        lineContent: $this->truncateLine($line),
                        matchText: $this->truncateMatch($matchText),
                        toolSignature: $toolSig,
                    );
                }
            }
        }

        $dto = new ChatHistoryGrepResultDto(
            pattern: $pattern,
            caseInsensitive: $caseInsensitive,
            includeToolMessages: $includeToolMessages,
            matches: $matches,
            truncated: $truncated,
            totalMatches: $totalMatches,
            messagesSearched: $messagesSearched,
        );

        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Строит regex для поиска по строкам.
     *
     * Если входная строка является валидным regex (с разделителями), используется она.
     * Иначе строка экранируется и оборачивается в `/.../u`.
     *
     * @param string $pattern
     * @param bool   $caseInsensitive
     *
     * @return string|null
     */
    private function buildRegex(string $pattern, bool $caseInsensitive): ?string
    {
        if ($pattern === '') {
            return null;
        }

        if (@preg_match($pattern, '') !== false) {
            $regex = $pattern;
            if (!str_contains($regex, 'u')) {
                $regex = $this->tryAddModifier($regex, 'u') ?? $regex;
            }
            if ($caseInsensitive && !str_contains($regex, 'i')) {
                $regex = $this->tryAddModifier($regex, 'i') ?? $regex;
            }
            return @preg_match($regex, '') !== false ? $regex : null;
        }

        $mods = $caseInsensitive ? 'iu' : 'u';
        $regex = '/' . preg_quote($pattern, '/') . '/' . $mods;
        if (@preg_match($regex, '') !== false) {
            return $regex;
        }

        return null;
    }

    /**
     * Пытается добавить модификатор к regex вида /.../mods.
     *
     * Работает только для шаблонов с явными разделителями (как минимум первый символ — delimiter).
     *
     * @param string $regex
     * @param string $modifier
     *
     * @return string|null
     */
    private function tryAddModifier(string $regex, string $modifier): ?string
    {
        if ($regex === '' || $modifier === '' || str_contains($regex, $modifier)) {
            return null;
        }

        $delim = $regex[0];
        $len = mb_strlen($regex);
        if ($len < 3) {
            return null;
        }

        $endPos = $this->findLastUnescapedDelimiterPos($regex, $delim);
        if ($endPos === null || $endPos === 0) {
            return null;
        }

        $body = mb_substr($regex, 1, $endPos - 1);
        $mods = mb_substr($regex, $endPos + 1);

        return $delim . $body . $delim . $mods . $modifier;
    }

    /**
     * Находит позицию последнего неэкранированного delimiter в строке regex.
     *
     * @param string $regex
     * @param string $delim
     *
     * @return int|null
     */
    private function findLastUnescapedDelimiterPos(string $regex, string $delim): ?int
    {
        $len = mb_strlen($regex);
        for ($i = $len - 1; $i >= 1; $i--) {
            $ch = mb_substr($regex, $i, 1);
            if ($ch !== $delim) {
                continue;
            }

            $slashes = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                if (mb_substr($regex, $j, 1) === '\\') {
                    $slashes++;
                } else {
                    break;
                }
            }

            if (($slashes % 2) === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Усечение строки результата (lineContent).
     */
    private function truncateLine(string $line): string
    {
        if (mb_strlen($line) <= $this->maxLineLength) {
            return $line;
        }

        return mb_substr($line, 0, $this->maxLineLength - 3) . '...';
    }

    /**
     * Усечение совпавшего фрагмента (matchText).
     */
    private function truncateMatch(string $matchText): string
    {
        if (mb_strlen($matchText) <= $this->maxMatchLength) {
            return $matchText;
        }

        return mb_substr($matchText, 0, $this->maxMatchLength - 3) . '...';
    }
}
