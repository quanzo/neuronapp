<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\trimmers;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

use function array_merge;
use function array_slice;
use function count;
use function in_array;

/**
 * Компактный обрезчик истории чата с интеллектуальным сжатием.
 *
 * В отличие от стандартного {@see \NeuronAI\Chat\History\HistoryTrimmer}, который
 * просто удаляет старые сообщения, этот класс:
 * - сохраняет \"хвост\" диалога (последние значимые обмены) без изменений;
 * - сворачивает \"голову\" истории в один или несколько суммаризирующих сообщений;
 * - старается не разрывать пары ToolCall/ToolResult и поддерживать валидную структуру диалога.
 *
 * Основная идея: старые детали диалога представляются в виде короткого резюме,
 * а последние сообщения остаются доступными в полном виде для LLM.
 */
final class HistoryCompactTrimmer implements HistoryTrimmerInterface
{
    /**
     * Общее количество токенов в истории после последнего вызова trim().
     *
     * Значение основывается на оценке токенов через {@see TokenCounter}.
     */
    private int $totalTokens = 0;

    /**
     * Максимальное количество пунктов summary для сообщений пользователя.
     */
    private int $maxUserSummaryItems = 10;

    /**
     * Максимальное количество пунктов summary для сообщений ассистента/модели.
     */
    private int $maxAssistantSummaryItems = 10;

    /**
     * Доля контекстного окна, которую можно отдать под summary.
     *
     * Например, 0.2 означает, что summary не должно занимать более 20% окна.
     */
    private float $summaryRatio = 0.2;

    /**
     * Жёсткий верхний предел токенов, которые можно потратить на summary.
     *
     * Используется совместно с {@see $summaryRatio}: фактический бюджет — минимум из них.
     */
    private int $summaryMaxTokens = 512;

    /**
     * @param TokenCounter $tokenCounter Счётчик токенов для оценки размера сообщений.
     * @param float        $tailRatio    Доля контекстного окна, отдаваемая на \"хвост\" (0.0–1.0).
     */
    public function __construct(
        private readonly TokenCounter $tokenCounter = new TokenCounter(),
        private readonly float $tailRatio = 0.6,
    ) {
    }

    /**
     * Возвращает общее количество токенов после последнего вызова trim().
     */
    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    /**
     * Интеллектуально обрезает историю, сочетая суммаризацию старых сообщений и сохранение хвоста.
     *
     * Алгоритм:
     * 1. Если история пуста — вернуть пустой массив.
     * 2. Оценить общее число токенов; если оно умещается в contextWindow — вернуть историю как есть.
     * 3. Выделить хвост (tail), двигаясь от конца истории назад, пока его размер не достигнет tailRatio * contextWindow.
     *    При этом не разрывать пары ToolCall/ToolResult.
     * 4. Голову (head) до хвоста свернуть в одно суммаризирующее сообщение с ролью DEVELOPER,
     *    содержащее компактное резюме предыдущего диалога.
     * 5. Собрать новую историю: [summary?, tail...] и при необходимости дополнительно сократить,
     *    если общие токены всё ещё превышают окно.
     *
     * @param Message[] $messages      Исходная история сообщений.
     * @param int       $contextWindow Максимальный размер контекста в токенах.
     *
     * @return Message[] Обрезанная и частично суммаризованная история.
     */
    public function trim(array $messages, int $contextWindow): array
    {
        if ($messages === []) {
            $this->totalTokens = 0;
            return [];
        }

        // 1. Грубая оценка токенов по всей истории.
        $total = $this->computeTotalTokens($messages);
        $this->totalTokens = $total;

        if ($total <= $contextWindow || $contextWindow <= 0) {
            // История уже умещается — возвращаем как есть.
            return $messages;
        }

        // 2. Выбираем хвост, который нужно сохранить целиком.
        $tailStart = $this->selectTailStartIndex($messages, $contextWindow);
        $head = array_slice($messages, 0, $tailStart);
        $tail = array_slice($messages, $tailStart);

        // 3. Строим суммаризирующее сообщение по голове, если она не пуста.
        $summaryMessages = [];
        if ($head !== []) {
            $summary = $this->buildSummaryMessage($head, $tail, $contextWindow);
            if ($summary !== null) {
                $summaryMessages[] = $summary;
            }
        }

        $compact = array_merge($summaryMessages, $tail);
        $this->totalTokens = $this->computeTotalTokens($compact);

        // 4. Если после суммаризации мы всё ещё превышаем окно — аккуратно сдвигаем границу головы.
        if ($this->totalTokens > $contextWindow) {
            $compact = $this->shrinkHeadIfNeeded($compact, $contextWindow);
        }

        // 5. Гарантируем валидную структуру диалога, не разрывая ToolCall/ToolResult.
        // Если в сжатой истории есть пара ToolCall/ToolResult подряд, оставляем её как есть
        // и не применяем строгую валидацию последовательности, чтобы не потерять пару.
        $hasToolPair = false;
        for ($i = 0, $n = count($compact) - 1; $i < $n; $i++) {
            if ($compact[$i] instanceof ToolCallMessage && $compact[$i + 1] instanceof ToolResultMessage) {
                $hasToolPair = true;
                break;
            }
        }

        if (!$hasToolPair) {
            $compact = $this->ensureValidMessageSequence($compact);
        }

        $this->totalTokens = $this->computeTotalTokens($compact);

        return $compact;
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
     * Определяет индекс начала хвоста, который нужно сохранить целиком.
     *
     * Хвост выбирается так, чтобы его примерная стоимость по токенам не превышала
     * tailRatio * contextWindow, при этом:
     * - избегается разрыв пар ToolCall/ToolResult;
     * - предпочтительно начинать хвост с пользовательского сообщения (USER/DEVELOPER).
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
        $tailStart = $count; // индекс первого сообщения хвоста (считаем с конца)

        for ($i = $count - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            $tailTokens += $this->tokenCounter->count($message);
            $tailStart = $i;

            // Не разрываем ToolResult, если перед ним ToolCall.
            if ($message instanceof ToolResultMessage && $i > 0 && $messages[$i - 1] instanceof ToolCallMessage) {
                $tailTokens += $this->tokenCounter->count($messages[$i - 1]);
                $tailStart = $i - 1;
                $i--;
            }

            if ($tailTokens >= $targetTokens) {
                break;
            }
        }

        // Попытка выровнять границу по первому пользовательскому сообщению в хвосте,
        // но только если это не разорвёт пару ToolCall/ToolResult в начале хвоста.
        $startsWithToolPair = $tailStart < $count - 1
            && $messages[$tailStart] instanceof ToolCallMessage
            && $messages[$tailStart + 1] instanceof ToolResultMessage;

        if (!$startsWithToolPair) {
            for ($j = $tailStart; $j < $count; $j++) {
                $role = $messages[$j]->getRole();
                if ($role === MessageRole::USER->value || $role === MessageRole::DEVELOPER->value) {
                    $tailStart = $j;
                    break;
                }
            }
        }

        // Финальная защита от разрыва пары ToolCall/ToolResult:
        // если после выравнивания границы хвоста первое сообщение — ToolResult,
        // а перед ним в истории идёт ToolCall, сдвигаем границу на ToolCall.
        if (
            $tailStart > 0
            && $messages[$tailStart] instanceof ToolResultMessage
            && $messages[$tailStart - 1] instanceof ToolCallMessage
        ) {
            $tailStart--;
        }

        return $tailStart;
    }

    /**
     * Строит одно суммаризирующее сообщение по старой части истории.
     *
     * Здесь используется простая эвристика:\n
     * - собираем краткий перечень вопросов пользователя и основных ответов ассистента;
     * - формируем одно сообщение с ролью DEVELOPER, которое описывает контекст.
     *
     * В дальнейшем алгоритм можно заменить на более продвинутый (например, отдельный
     * вызов LLM для суммаризации), не меняя интерфейс класса.
     *
     * @param Message[] $headMessages Сообщения, относящиеся к \"голове\" истории.
     * @param Message[] $tailMessages Сообщения, относящиеся к \"хвосту\" истории (для исключения дубликатов).
     * @param int       $contextWindow Размер контекстного окна в токенах.
     */
    private function buildSummaryMessage(array $headMessages, array $tailMessages, int $contextWindow): ?Message
    {
        if ($headMessages === []) {
            return null;
        }

        $userPoints = [];
        $assistantPoints = [];

        // Бюджет токенов на summary: минимум между долей окна и жёстким лимитом.
        $summaryBudget = (int) max(
            1,
            min(
                $this->summaryMaxTokens,
                (int) ($this->summaryRatio * $contextWindow)
            )
        );

        // Наборы уже учтённых сообщений (по нормализованному контенту) —
        // как из хвоста, так и из головы, чтобы избежать дубликатов.
        $seenUser = [];
        $seenAssistant = [];

        foreach ($tailMessages as $message) {
            $role = $message->getRole();
            $content = $message->getContent() ?? '';
            if ($content === '') {
                continue;
            }

            $normalized = $this->normalizeContent($content);

            if ($role === MessageRole::USER->value) {
                $seenUser[$normalized] = true;
            } elseif ($role === MessageRole::ASSISTANT->value || $role === MessageRole::MODEL->value) {
                $seenAssistant[$normalized] = true;
            }
        }

        $currentSummaryText = 'Краткое резюме предыдущего диалога:';
        $currentTokens = $this->tokenCounter->count(new Message(MessageRole::DEVELOPER, $currentSummaryText));

        foreach ($headMessages as $message) {
            $role = $message->getRole();
            $content = $message->getContent() ?? '';

            if ($content === '') {
                continue;
            }

            $normalized = $this->normalizeContent($content);

            if ($role === MessageRole::USER->value) {
                if (isset($seenUser[$normalized]) || count($userPoints) >= $this->maxUserSummaryItems) {
                    continue;
                }

                $candidateLine = '- ' . trim($content);
                $trialText = $currentSummaryText . "\n\n" . 'Основные вопросы и запросы пользователя:' . "\n" . implode("\n", [...$userPoints, $candidateLine]);
                $trialTokens = $this->tokenCounter->count(new Message(MessageRole::DEVELOPER, $trialText));

                if ($trialTokens > $summaryBudget) {
                    // Дальнейшее добавление только увеличит размер, поэтому выходим.
                    break;
                }

                $userPoints[] = $candidateLine;
                $seenUser[$normalized] = true;
                $currentSummaryText = $trialText;
                $currentTokens = $trialTokens;
            } elseif ($role === MessageRole::ASSISTANT->value || $role === MessageRole::MODEL->value) {
                if (isset($seenAssistant[$normalized]) || count($assistantPoints) >= $this->maxAssistantSummaryItems) {
                    continue;
                }

                $candidateLine = '- ' . trim($content);

                // Пробуем добавить как часть блока \"ответов ассистента\".
                $baseText = $currentSummaryText;
                if ($userPoints !== []) {
                    $baseText .= "\n\n" . 'Основные вопросы и запросы пользователя:' . "\n" . implode("\n", $userPoints);
                }
                $trialText = $baseText . "\n\n" . 'Ключевые ответы и выводы ассистента:' . "\n" . implode("\n", [...$assistantPoints, $candidateLine]);
                $trialTokens = $this->tokenCounter->count(new Message(MessageRole::DEVELOPER, $trialText));

                if ($trialTokens > $summaryBudget) {
                    break;
                }

                $assistantPoints[] = $candidateLine;
                $seenAssistant[$normalized] = true;
                $currentSummaryText = $trialText;
                $currentTokens = $trialTokens;
            }
        }

        if ($userPoints === [] && $assistantPoints === []) {
            return null;
        }

        $summaryLines = [];
        $summaryLines[] = 'Краткое резюме предыдущего диалога:';

        if ($userPoints !== []) {
            $summaryLines[] = '';
            $summaryLines[] = 'Основные вопросы и запросы пользователя:';
            $summaryLines[] = implode("\n", $userPoints);
        }

        if ($assistantPoints !== []) {
            $summaryLines[] = '';
            $summaryLines[] = 'Ключевые ответы и выводы ассистента:';
            $summaryLines[] = implode("\n", $assistantPoints);
        }

        $text = implode("\n", $summaryLines);

        return new Message(MessageRole::DEVELOPER, $text);
    }

    /**
     * Дополнительно уменьшает объём головы истории, если после суммаризации
     * мы всё ещё выходим за пределы контекстного окна.
     *
     * На этом шаге мы не трогаем хвост, а лишь укорачиваем/убираем summary‑сообщения.
     *
     * @param Message[] $messages Уже компактная история (summary + tail).
     *
     * @return Message[] Потенциально ещё более сжатая история.
     */
    private function shrinkHeadIfNeeded(array $messages, int $contextWindow): array
    {
        if ($messages === []) {
            return [];
        }

        // Разделяем summary и хвост: считаем, что summary в начале и имеет роль DEVELOPER.
        $head = [];
        $tail = $messages;

        foreach ($messages as $index => $message) {
            if ($message->getRole() === MessageRole::DEVELOPER->value) {
                $head[] = $message;
                unset($tail[$index]);
            } else {
                // как только встретили не-DEVELOPER — считаем, что summary‑часть закончилась
                $tail = array_slice($messages, $index);
                break;
            }
        }

        // Если убрать все summary‑сообщения, получим только хвост.
        $onlyTail = $tail;
        if ($onlyTail !== []) {
            $tokens = $this->computeTotalTokens($onlyTail);
            if ($tokens <= $contextWindow) {
                return $onlyTail;
            }
        }

        // В крайнем случае полагаемся на то, что внешний код может дополнительно
        // применить более жёсткий триммер. Здесь просто возвращаем то, что есть.
        return $messages;
    }

    /**
     * Обеспечивает валидную последовательность сообщений диалога.
     *
     * Адаптированная версия логики из базового {@see \NeuronAI\Chat\History\HistoryTrimmer}:
     * - удаляет ведущие tool‑сообщения;
     * - обеспечивает старт с пользовательской роли (USER/DEVELOPER);
     * - выстраивает корректное чередование ролей, не разрывая пары ToolCall/ToolResult.
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
            return [];
        }
        // Пытаемся сохранить последнюю пару ToolCall/ToolResult перед первым пользовательским
        // сообщением, если она есть, чтобы не терять важный контекст инструментов.
        $sliceStart = $firstUserIndex;
        if ($firstUserIndex > 0) {
            for ($i = $firstUserIndex - 1; $i > 0; $i--) {
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
     * Гарантирует корректное чередование ролей пользователя и ассистента,
     * не разрывая пары ToolCall/ToolResult.
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
            // ToolCall всегда сохраняем как часть истории, независимо от ожидаемой роли.
            if ($message instanceof ToolCallMessage) {
                $result[] = $message;
                // После вызова инструмента ожидаем результат (ToolResult) от \"пользовательской\" роли.
                $expectingRoles = $userRoles;
                continue;
            }

            // ToolResult стараемся привязать к предыдущему ToolCall.
            if ($message instanceof ToolResultMessage) {
                if ($result !== [] && $result[count($result) - 1] instanceof ToolCallMessage) {
                    $result[] = $message;
                    // После пары ToolCall/ToolResult снова ожидаем ответ ассистента.
                    $expectingRoles = $assistantRoles;
                    continue;
                }

                $role = $message->getRole();
                if (in_array($role, $expectingRoles, true)) {
                    $result[] = $message;
                    $expectingRoles = ($expectingRoles === $userRoles) ? $assistantRoles : $userRoles;
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

    /**
     * Нормализует текст для сравнения и детектирования дубликатов.
     *
     * Удаляет лишние пробелы и приводит строку к нижнему регистру, чтобы
     * \"Question\", \"question\" и \" question  \" считались одним и тем же контентом.
     */
    private function normalizeContent(string $content): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($content));

        if ($normalized === null) {
            $normalized = trim($content);
        }

        return mb_strtolower($normalized);
    }
}
