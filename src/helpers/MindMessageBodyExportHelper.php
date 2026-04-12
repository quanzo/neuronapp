<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

/**
 * Преобразование сообщений NeuronAI в плоский текст для файла долговременной памяти.
 *
 * Пример:
 *
 * <code>
 * $text = MindMessageBodyExportHelper::toStoragePlainBody($message);
 * </code>
 */
final class MindMessageBodyExportHelper
{
    /**
     * Собирает строку тела сообщения для записи в `.mind` (UTF-8).
     *
     * Для {@see ToolCallMessage} и {@see ToolResultMessage} используется JSON-сериализация.
     * Для обычного {@see NeuronMessage} текстовые блоки склеиваются переводами строк,
     * прочие блоки кодируются в JSON построчно.
     *
     * @param mixed $message Сообщение NeuronAI или неподдерживаемый тип.
     *
     * @return string Текст для тела блока (может быть пустой строкой).
     */
    public static function toStoragePlainBody(mixed $message): string
    {
        if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage) {
            return JsonHelper::encodeThrow($message->jsonSerialize());
        }

        if (!$message instanceof NeuronMessage) {
            return '';
        }

        $lines = [];
        foreach ($message->getContentBlocks() as $block) {
            $lines[] = self::exportBlock($block);
        }

        return implode("\n", $lines);
    }

    /**
     * Экспортирует один content-block в строку.
     *
     * @param ContentBlockInterface $block Блок содержимого сообщения.
     *
     * @return string Текстовое представление блока.
     */
    private static function exportBlock(ContentBlockInterface $block): string
    {
        if ($block instanceof TextContent) {
            return $block->getContent();
        }

        return JsonHelper::encodeThrow($block->toArray());
    }
}
