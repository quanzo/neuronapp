<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\attachments;

use app\modules\neuron\enums\AttachmentTypeEnum;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;

/**
 * Базовый DTO-дополнения (вложения), передаваемого вместе с запросом в LLM.
 *
 * Наследники описывают конкретный тип вложения (текст, картинка, файл и т.п.)
 * и предоставляют данные в формате, пригодном для формирования сообщения
 * или вспомогательных структур NeuronAI.
 */
abstract class AttachmentDto
{
    /**
     * Возвращает тип вложения в виде перечисления {@see AttachmentTypeEnum}.
     *
     * Значение enum используется при построении полезной нагрузки для LLM.
     *
     * @return AttachmentTypeEnum Тип текущего вложения.
     */
    abstract public function getAttachmentType(): AttachmentTypeEnum;

    /**
     * Возвращает дополнительные метаданные вложения.
     *
     * Метаданные могут использоваться адаптером провайдера LLM для выбора
     * типа источника (id/url/base64), MIME-типа, имени файла и т.п.
     *
     * @return array<string, mixed>
     */
    abstract public function getMetadata(): array;

    /**
     * Возвращает content block NeuronAI, соответствующий данному вложению.
     *
     * Блок добавляется напрямую в {@see \NeuronAI\Chat\Messages\Message} и далее
     * маппится провайдером (OpenAI/Anthropic/Gemini и т.п.) в нужный формат API.
     *
     * Если вложение указывает на файл, реализация должна вернуть блок с содержимым файла,
     * т.е. прочитать файл и подготовить данные (обычно base64 + mime/filename).
     */
    abstract public function getContentBlock(): ContentBlockInterface;
}

