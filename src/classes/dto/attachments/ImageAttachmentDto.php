<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\attachments;

use app\modules\neuron\enums\AttachmentTypeEnum;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;

/**
 * DTO вложения с изображением, передаваемым по ссылке или в виде base64.
 *
 * Конкретный способ интерпретации данных (URL, base64 и т.п.) определяется
 * адаптером NeuronAI, который читает поле data и metadata.
 */
final class ImageAttachmentDto extends AttachmentDto
{
    /**
     * Создаёт DTO вложения с изображением.
     *
     * @param string            $data     Данные изображения (URL, base64 и т.п.).
     * @param string|null       $label    Опциональная метка/заголовок вложения.
     * @param array<string,mixed> $metadata Дополнительные метаданные для адаптера LLM.
     */
    public function __construct(
        private readonly string $data,
        private readonly ?string $label = null,
        private readonly array $metadata = [],
    ) {
    }

    /**
     * Возвращает тип вложения как {@see AttachmentTypeEnum::IMAGE}.
     *
     * @return AttachmentTypeEnum Тип вложения-изображения.
     */
    public function getAttachmentType(): AttachmentTypeEnum
    {
        return AttachmentTypeEnum::IMAGE;
    }

    /**
     * Возвращает данные изображения.
     *
     * @return string Строковое представление данных изображения.
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * Возвращает NeuronAI блок изображения ({@see ImageContent}) для добавления в сообщение.
     *
     * Источник изображения можно уточнить в metadata:\n
     * - source_type: url|base64|id\n
     * - media_type: mime (нужно для base64 / data-uri)\n
     *
     * По умолчанию:\n
     * - если data начинается с `data:` — считается data-uri и превращается в BASE64\n
     * - если data валиден как URL — считается URL\n
     * - иначе — считается BASE64\n
     *
     * @return ContentBlockInterface
     */
    public function getContentBlock(): ContentBlockInterface
    {
        $data = $this->getData();
        $meta = $this->getMetadata();

        // data-uri
        if (preg_match('/^data:(?<mime>[^;]+);base64,(?<b64>.+)$/', $data, $m) === 1) {
            $mime = $m['mime'] ?? null;
            $b64 = $m['b64'] ?? '';
            $block = new ImageContent($b64, SourceType::BASE64, is_string($mime) ? $mime : null);
            $block->setMetadata($meta);
            return $block;
        }

        $sourceType = null;
        $sourceTypeRaw = $meta['source_type'] ?? null;
        if (is_string($sourceTypeRaw) && $sourceTypeRaw !== '') {
            try {
                $sourceType = SourceType::from($sourceTypeRaw);
            } catch (\ValueError) {
                $sourceType = null;
            }
        }

        if ($sourceType === null) {
            $sourceType = filter_var($data, FILTER_VALIDATE_URL) ? SourceType::URL : SourceType::BASE64;
        }

        $mediaType = $meta['media_type'] ?? null;
        $block = new ImageContent($data, $sourceType, is_string($mediaType) ? $mediaType : null);
        $block->setMetadata($meta);
        return $block;
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
     * Возвращает дополнительные метаданные вложения с изображением.
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
}
