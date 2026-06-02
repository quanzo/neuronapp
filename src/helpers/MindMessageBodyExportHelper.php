<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

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
        // BC-wrapper: реализация переехала в `src/mind/helpers`.
        return \app\modules\neuron\mind\helpers\MindMessageBodyExportHelper::toStoragePlainBody($message);
    }
}
