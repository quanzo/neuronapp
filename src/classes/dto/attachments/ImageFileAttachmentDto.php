<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\attachments;

use app\modules\neuron\enums\AttachmentTypeEnum;
use app\modules\neuron\interfaces\IAttachmentFile;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;

/**
 * DTO вложения с файлом изображения.
 *
 * Содержит путь к файлу или идентификатор ресурса с изображением. Адаптер
 * NeuronAI сам решает, как читать и интерпретировать этот ресурс.
 */
final class ImageFileAttachmentDto extends AttachmentDto implements IAttachmentFile
{
    /**
     * Создаёт DTO вложения с файлом изображения.
     *
     * @param string            $path     Путь к файлу или идентификатор ресурса с изображением.
     * @param string|null       $label    Опциональная метка/заголовок вложения.
     * @param array<string,mixed> $metadata Дополнительные метаданные для адаптера LLM.
     */
    public function __construct(
        private readonly string $path,
        private readonly ?string $label = null,
        private readonly array $metadata = [],
    ) {
    }

    /**
     * Возвращает тип вложения как {@see AttachmentTypeEnum::IMAGE_FILE}.
     *
     * @return AttachmentTypeEnum Тип вложения-файла изображения.
     */
    public function getAttachmentType(): AttachmentTypeEnum
    {
        return AttachmentTypeEnum::IMAGE_FILE;
    }

    /**
     * Возвращает путь к файлу изображения или идентификатор ресурса.
     *
     * @return string Путь или идентификатор файла изображения.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Возвращает NeuronAI блок изображения ({@see ImageContent}) с содержимым.
     *
     * Если `path` указывает на локальный читаемый файл — он будет прочитан и
     * закодирован в base64; mime определяется автоматически (или берётся из metadata).\n
     *
     * Если `path` не является локальным файлом, то интерпретация зависит от metadata:\n
     * - source_type: url|base64|id\n
     * - media_type: mime (для base64)\n
     *
     * @return ContentBlockInterface
     */
    public function getContentBlock(): ContentBlockInterface
    {
        $path = $this->getPath();
        $meta = $this->getMetadata();

        // Local file -> base64
        if (is_file($path) && is_readable($path)) {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                throw new \RuntimeException("Unable to read attachment image file: {$path}");
            }

            $base64 = base64_encode($raw);
            $mime = $this->detectMimeType($path) ?? ($meta['media_type'] ?? null);

            $block = new ImageContent($base64, SourceType::BASE64, is_string($mime) ? $mime : null);
            $block->setMetadata($meta);
            return $block;
        }

        // data-uri
        if (preg_match('/^data:(?<mime>[^;]+);base64,(?<b64>.+)$/', $path, $m) === 1) {
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
            $sourceType = filter_var($path, FILTER_VALIDATE_URL) ? SourceType::URL : SourceType::ID;
        }

        $mime = $meta['media_type'] ?? null;
        $block = new ImageContent($path, $sourceType, is_string($mime) ? $mime : null);
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
     * Возвращает дополнительные метаданные вложения с файлом изображения.
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
     * Определяет MIME-тип локального файла.
     *
     * @param string $path Путь к файлу.
     */
    private function detectMimeType(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return is_string($mime) && $mime !== '' ? $mime : null;
    }
}

