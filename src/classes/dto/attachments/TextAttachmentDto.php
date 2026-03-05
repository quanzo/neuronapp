<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\attachments;

use app\modules\neuron\enums\AttachmentTypeEnum;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

/**
 * DTO вложения с дополнительным текстовым содержимым.
 *
 * Используется для передачи в LLM отдельных текстовых блоков, которые
 * не входят напрямую в основной промпт (например, справочная информация,
 * длинные описания, дополнительные инструкции).
 */
final class TextAttachmentDto extends AttachmentDto
{
    /**
     * Создаёт DTO текстового вложения.
     *
     * @param string      $content  Текстовое содержимое вложения.
     * @param string|null $label    Опциональная метка/заголовок вложения.
     * @param array<string,mixed> $metadata Дополнительные метаданные для адаптера LLM.
     */
    public function __construct(
        private readonly string $content,
        private readonly ?string $label = null,
        private readonly array $metadata = [],
    ) {
    }

    /**
     * Возвращает тип вложения как {@see AttachmentTypeEnum::TEXT}.
     *
     * @return AttachmentTypeEnum Тип текстового вложения.
     */
    public function getAttachmentType(): AttachmentTypeEnum
    {
        return AttachmentTypeEnum::TEXT;
    }

    /**
     * Возвращает текстовое содержимое вложения.
     *
     * @return string Текст вложения.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Возвращает метку/заголовок вложения, если она задана.
     *
     * @return string|null Метка вложения или null, если не задана.
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * Возвращает дополнительные метаданные вложения.
     *
     * @return array<string, mixed> Ассоциативный массив метаданных.
     */
    public function getMetadata(): array
    {
        return array_filter(
            array_merge(
                $this->metadata,
                $this->label !== null ? ['label' => $this->label] : []
            ),
            static fn (mixed $value): bool => $value !== null
        );
    }

    /**
     * Возвращает NeuronAI блок текста ({@see TextContent}) для добавления в сообщение.
     *
     * @return ContentBlockInterface
     */
    public function getContentBlock(): ContentBlockInterface
    {
        $block = new TextContent($this->getContent());
        $block->setMetadata($this->getMetadata());
        return $block;
    }
}

