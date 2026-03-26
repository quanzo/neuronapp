<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\trimmers;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

use function array_slice;
use function count;
use function in_array;

/**
 * Триммер истории чата с «плавающим» контекстным окном.
 *
 * Основные возможности:
 * - режим «прилипания к хвосту» — окно всегда строится от конца истории, как в
 *   стандартном {@see \NeuronAI\Chat\History\HistoryTrimmer};
 * - режим «ручного якоря» — окно строится вокруг произвольного сообщения в истории,
 *   на которое указывает индекс якоря;
 * - при выборе сообщений учитывается приблизительный размер в токенах через
 *   {@see TokenCounter}, чтобы уложиться в заданное контекстное окно;
 * - сохраняется валидная структура диалога: чередование ролей и пары
 *   {@see ToolCallMessage}/{@see ToolResultMessage} не разрываются.
 *
 * Класс не обрезает полную историю: вызывающий код хранит весь массив сообщений,
 * а метод {@see trim()} возвращает только тот подмассив, который нужно отдать LLM.
 *
 * Пример использования (режим «прилипания к хвосту», окно от конца истории):
 *
 * <code>
 * $trimmer = new FluidContextWindowTrimmer(new TokenCounter());
 *
 * // Полная история всего диалога
 * $fullHistory = $conversation->getMessages();
 *
 * // Размер контекста в токенах для конкретной модели
 * $contextWindow = 8_000;
 *
 * // Окно будет автоматически построено от хвоста истории
 * $messagesForLlm = $trimmer->trim($fullHistory, $contextWindow);
 * </code>
 *
 * Пример использования (ручной якорь, окно вокруг конкретного сообщения):
 *
 * <code>
 * $anchorIndex = $ui->getFocusedMessageIndex(); // например, сообщение, на котором сейчас фокус
 *
 * $trimmer = (new FluidContextWindowTrimmer(new TokenCounter()))
 *     ->withAnchorIndex($anchorIndex)     // переключаемся в режим ручного якоря
 *     ->withTailMode(false);             // явно отключаем «прилипание» к хвосту
 *
 * $messagesForLlm = $trimmer->trim($fullHistory, $contextWindow);
 * </code>
 *
 * При необходимости можно вернуться к хвосту:
 *
 * <code>
 * $trimmer
 *     ->resetAnchor()     // сбрасываем сохранённый индекс якоря
 *     ->withTailMode();   // включаем режим прилипания к хвосту (по умолчанию true)
 * </code>
 */
final class FluidContextWindowTrimmer implements HistoryTrimmerInterface
{
    /**
     * Общее количество токенов в последнем возвращённом срезе истории.
     */
    private int $totalTokens = 0;

    /**
     * Индекс якорного сообщения относительно переданного массива сообщений.
     *
     * Если значение равно null или выходит за границы массива, то в качестве якоря
     * используется последнее сообщение истории.
     */
    private ?int $anchorIndex = null;

    /**
     * Флаг режима «прилипания к хвосту».
     *
     * Когда включён, фактический якорь всегда считается последним сообщением
     * истории, даже если ранее был установлен явный {@see $anchorIndex}.
     */
    private bool $tailMode = true;

    public function __construct(
        private readonly TokenCounter $tokenCounter = new TokenCounter(),
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
     * Устанавливает индекс якорного сообщения и возвращает триммер для fluent‑стиля.
     *
     * @param int|null $anchorIndex Индекс сообщения (0-based) или null для использования хвоста истории.
     */
    public function withAnchorIndex(?int $anchorIndex): self
    {
        $this->anchorIndex = $anchorIndex;
        $this->tailMode = false;

        return $this;
    }

    /**
     * Сбрасывает якорь на значение по умолчанию (последнее сообщение истории).
     */
    public function resetAnchor(): self
    {
        $this->anchorIndex = null;

        return $this;
    }

    /**
     * Включает или отключает режим «прилипания окна к хвосту истории».
     *
     * При включённом режиме фактический якорь всегда выбирается как последнее
     * сообщение истории, даже если ранее был задан {@see withAnchorIndex()}.
     * При включении режима сохранённый индекс якоря сбрасывается.
     *
     * @param bool $enabled Включить/выключить режим прилипания к хвосту.
     */
    public function withTailMode(bool $enabled = true): self
    {
        $this->tailMode = $enabled;

        if ($enabled) {
            $this->anchorIndex = null;
        }

        return $this;
    }

    /**
     * Формирует срез истории вокруг выбранного якоря, укладывающийся в контекстное окно.
     *
     * Алгоритм:
     * 1. Определяется фактический индекс якоря: либо заданный через {@see withAnchorIndex()},
     *    либо индекс последнего сообщения.
     * 2. Двигаемся от якоря назад, суммируя стоимость сообщений в токенах, пока она
     *    не превысит размер контекстного окна.
     * 3. При необходимости сохраняем неделимые пары ToolCall/ToolResult, даже если это
     *    немного превышает лимит.
     * 4. Вырезаем подмассив сообщений и нормализуем его структуру диалога.
     *
     * @param Message[] $messages      Полная история сообщений.
     * @param int       $contextWindow Максимальный размер контекста в токенах.
     *
     * @return Message[] Подмассив сообщений вокруг якоря.
     */
    public function trim(array $messages, int $contextWindow): array
    {
        if ($messages === []) {
            $this->totalTokens = 0;

            return [];
        }

        $count = count($messages);
        $anchor = $this->anchorIndex;

        if ($this->tailMode || $anchor === null || $anchor < 0 || $anchor >= $count) {
            $anchor = $count - 1;
        }

        if ($contextWindow <= 0) {
            // В крайнем случае всегда возвращаем хотя бы сообщение-«якорь».
            $window = [$messages[$anchor]];
            $window = $this->ensureValidMessageSequence($window);
            $this->totalTokens = $this->computeTotalTokens($window);

            return $window;
        }

        [$startIndex, $endIndex] = $this->selectWindowBounds($messages, $anchor, $contextWindow);

        $window = array_slice($messages, $startIndex, $endIndex - $startIndex + 1);
        $window = $this->ensureValidMessageSequence($window);
        if (empty($window) && $anchor) {
            // В крайнем случае всегда возвращаем хотя бы сообщение-«якорь».
            $window = [$messages[$anchor]];
            //$window = $this->ensureValidMessageSequence($window);
            $aaa = 1;
        }
        $this->totalTokens = $this->computeTotalTokens($window);

        return $window;
    }

    /**
     * Вычисляет границы окна истории вокруг якоря, не превышающие контекстное окно.
     *
     * @param Message[] $messages
     *
     * @return array{0:int,1:int} [startIndex, endIndex]
     */
    private function selectWindowBounds(array $messages, int $anchor, int $contextWindow): array
    {
        $runningTotal = 0;
        $startIndex = $anchor;
        $endIndex = $anchor;

        for ($i = $anchor; $i >= 0; $i--) {
            $message = $messages[$i];
            $tokens = $this->tokenCounter->count($message);

            // Попытка добавить текущее сообщение в окно.
            if ($runningTotal > 0 && $runningTotal + $tokens > $contextWindow) {
                break;
            }

            $runningTotal += $tokens;
            $startIndex = $i;

            // Не разрываем пару ToolCall/ToolResult в начале окна.
            if (
                $message instanceof ToolResultMessage
                && $i > 0
                && $messages[$i - 1] instanceof ToolCallMessage
            ) {
                $pairTokens = $this->tokenCounter->count($messages[$i - 1]);

                if ($runningTotal + $pairTokens <= $contextWindow || $runningTotal === 0) {
                    $runningTotal += $pairTokens;
                    $startIndex = $i - 1;
                    $i--;
                } else {
                    // Окно уже не вмещает пару, но чтобы не разрывать её,
                    // позволяем лёгкое превышение лимита.
                    $runningTotal += $pairTokens;
                    $startIndex = $i - 1;
                    $i--;
                    break;
                }
            }
        }

        return [$startIndex, $endIndex];
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
     * Обеспечивает валидную последовательность сообщений диалога.
     *
     * Логика адаптирована из {@see \NeuronAI\Chat\History\HistoryTrimmer} и
     * {@see HistoryCompactTrimmer}:
     * - стараемся начинать с пользовательской роли (USER/DEVELOPER);
     * - по возможности сохраняем последнюю пару ToolCall/ToolResult перед пользователем;
     * - приводим последовательность к корректному чередованию ролей.
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
            if ($message instanceof ToolCallMessage) {
                $result[] = $message;
                $expectingRoles = $userRoles;
                continue;
            }

            if ($message instanceof ToolResultMessage) {
                if ($result !== [] && $result[count($result) - 1] instanceof ToolCallMessage) {
                    $result[] = $message;
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
}
