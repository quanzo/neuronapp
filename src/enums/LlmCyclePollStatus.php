<?php

declare(strict_types=1);

namespace app\modules\neuron\enums;

use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;

/**
 * Результат классификации ответа LLM на служебный вопрос «задача выполнена?» в {@see \app\modules\neuron\helpers\LlmCycleHelper::waitCycle}.
 *
 * Пример:
 *
 * <code>
 * $status = LlmCyclePollStatus::fromAgentAnswer($msgAnswer);
 * // Completed — явное YES; InProgress — явные NO или WAITING; Unclear — иначе.
 * </code>
 */
enum LlmCyclePollStatus
{
    /**
     * Модель явно подтвердила завершение текущей задачи (обычно ответ YES или WAITING).
     */
    case Completed;

    /**
     * Модель явно сообщила, что работа ещё идёт (NO).
     */
    case InProgress;

    /**
     * Ответ нельзя однозначно отнести к завершению или продолжению (пусто, только tool-call, длинный текст без ключевых слов в начале и т.п.).
     */
    case Unclear;

    /**
     * Классифицирует ответ агента на проверку «задача выполнена?» для цикла {@see \app\modules\neuron\helpers\LlmCycleHelper::waitCycle}.
     *
     * {@see self::Completed} — в первой непустой строке текста явное слово YES.\n
     * {@see self::InProgress} — в первой непустой строке явные NO или WAITING.\n
     * {@see self::Unclear} — пусто, только вызов инструментов, не-текстовый ответ, первая значимая строка не начинается с YES/NO/WAITING.\n
     * Ответ не {@see NeuronMessage} (например DTO структурированного вывода) считается завершением цикла (как раньше).
     *
     * @param mixed $msgAnswer Ответ агента (сообщение, DTO или null).
     *
     * @return self Классифицированный статус опроса.
     */
    public static function fromAgentAnswer(mixed $msgAnswer): self
    {
        if ($msgAnswer === null || $msgAnswer === false) {
            return self::Unclear;
        }

        if (!$msgAnswer instanceof NeuronMessage) {
            return self::Completed;
        }

        if ($msgAnswer instanceof ToolCallMessage) {
            return self::Unclear;
        }

        $text = $msgAnswer->getContent();
        if ($text === null || trim($text) === '') {
            return self::Unclear;
        }

        return self::classifyFirstMeaningfulLine($text);
    }

    /**
     * По полному тексту ответа смотрит первую непустую строку на ключевые слова YES, NO, WAITING.
     *
     * @param string $text Полный текст ответа ассистента.
     *
     * @return self Статус по первой значимой строке.
     */
    private static function classifyFirstMeaningfulLine(string $text): self
    {
        foreach (preg_split('/\R/u', $text) as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }

            if (preg_match('/^(YES|NO|WAITING)\b/iu', $t, $matches)) {
                $word = mb_strtoupper($matches[1]);

                return $word === 'YES' || $word === 'WAITING'
                    ? self::Completed
                    : self::InProgress;
            }

            return self::Unclear;
        }

        return self::Unclear;
    }
}
