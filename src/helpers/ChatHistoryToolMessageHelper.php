<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\tools\ToolSignatureDto;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

use function is_array;
use function is_object;
use function is_string;
use function method_exists;

/**
 * Хелпер для распознавания и извлечения данных tool-call/tool-result сообщений в истории.
 *
 * Нужен для:
 * - инструментов просмотра истории (chat_history.*);
 * - очистки полной истории от вызовов этих инструментов, чтобы не засорять контекст копиями.
 *
 * Так как внутреннее устройство сообщений NeuronAI может отличаться между версиями,
 * извлечение выполняется «мягко»: через method_exists(), jsonSerialize(), и fallback
 * на попытку разобрать getContent() как JSON.
 */
final class ChatHistoryToolMessageHelper
{
    /**
     * Возвращает сигнатуру инструмента для сообщения, если оно является tool-call/tool-result.
     */
    public static function extractToolSignature(Message $message): ?ToolSignatureDto
    {
        if (!$message instanceof ToolCallMessage && !$message instanceof ToolResultMessage) {
            return null;
        }

        $name = self::extractToolName($message);
        $arguments = self::extractToolArguments($message);

        $raw = null;
        if ($name === null || $arguments === null) {
            $raw = self::extractRawToolData($message);
        }

        return new ToolSignatureDto($name, $arguments, $raw);
    }

    /**
     * Проверяет, относится ли сообщение к одному из заданных инструментов.
     *
     * @param Message $message
     * @param list<string> $toolNames Полные имена инструментов (например, 'chat_history.size').
     */
    public static function isToolMessageInList(Message $message, array $toolNames): bool
    {
        if (!$message instanceof ToolCallMessage && !$message instanceof ToolResultMessage) {
            return false;
        }

        $name = self::extractToolName($message);
        if ($name !== null) {
            return in_array($name, $toolNames, true);
        }

        $raw = self::extractRawToolData($message);
        if (is_array($raw)) {
            $rawName = $raw['name'] ?? $raw['tool'] ?? $raw['toolName'] ?? null;
            if (is_string($rawName)) {
                return in_array($rawName, $toolNames, true);
            }
        }

        return false;
    }

    /**
     * Пытается извлечь имя инструмента из сообщения.
     */
    private static function extractToolName(Message $message): ?string
    {
        foreach (['getToolName', 'getName', 'toolName', 'name'] as $method) {
            if (method_exists($message, $method)) {
                $val = $message->{$method}();
                if (is_string($val) && $val !== '') {
                    return $val;
                }
            }
        }

        $raw = self::extractRawToolData($message);
        if (is_array($raw)) {
            foreach (['toolName', 'tool_name', 'name', 'tool'] as $key) {
                $val = $raw[$key] ?? null;
                if (is_string($val) && $val !== '') {
                    return $val;
                }
            }
        }

        return null;
    }

    /**
     * Пытается извлечь аргументы инструмента (если доступно).
     */
    private static function extractToolArguments(Message $message): mixed
    {
        foreach (['getArguments', 'getArgs', 'arguments', 'args'] as $method) {
            if (method_exists($message, $method)) {
                return $message->{$method}();
            }
        }

        $raw = self::extractRawToolData($message);
        if (is_array($raw)) {
            foreach (['arguments', 'args', 'input', 'params', 'parameters'] as $key) {
                if (array_key_exists($key, $raw)) {
                    return $raw[$key];
                }
            }
        }

        return null;
    }

    /**
     * Возвращает наиболее «сырое» представление tool-call/tool-result сообщения, если возможно.
     *
     * @return mixed|null
     */
    private static function extractRawToolData(Message $message): mixed
    {
        if ($message instanceof \JsonSerializable) {
            try {
                return $message->jsonSerialize();
            } catch (\Throwable) {
                // ignore
            }
        }

        if (method_exists($message, 'toArray')) {
            try {
                return $message->toArray();
            } catch (\Throwable) {
                // ignore
            }
        }

        $content = $message->getContent();
        if (is_string($content) && $content !== '') {
            $decoded = JsonHelper::decodeAssociative($content);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_object($message)) {
            // Последний шанс: публичные свойства/структура объекта не трогаем —
            // возвращаем null, чтобы не раздувать результат.
            return null;
        }

        return null;
    }
}
