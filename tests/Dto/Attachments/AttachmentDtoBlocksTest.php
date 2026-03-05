<?php

declare(strict_types=1);

namespace Tests\Dto\Attachments;

use app\modules\neuron\classes\dto\attachments\ImageAttachmentDto;
use app\modules\neuron\classes\dto\attachments\ImageFileAttachmentDto;
use app\modules\neuron\classes\dto\attachments\TextAttachmentDto;
use app\modules\neuron\classes\dto\attachments\TextFileAttachmentDto;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use PHPUnit\Framework\TestCase;

/**
 * Тесты преобразования DTO вложений в NeuronAI content blocks.
 *
 * Проверяем, что DTO в `src/classes/dto/attachments` корректно создают блоки
 * для прикрепления к сообщениям LLM, включая чтение локальных файлов.
 */
final class AttachmentDtoBlocksTest extends TestCase
{
    public function testTextAttachmentCreatesTextContentBlock(): void
    {
        $dto = new TextAttachmentDto('Hello', 'greeting', ['x' => 1]);
        $block = $dto->getContentBlock();

        $this->assertInstanceOf(TextContent::class, $block);
        $this->assertSame('Hello', $block->content);
        $serialized = $block->jsonSerialize();
        $this->assertSame(['x' => 1, 'label' => 'greeting'], $serialized['meta'] ?? null);
    }

    public function testImageAttachmentCreatesImageContentBlockFromUrl(): void
    {
        $dto = new ImageAttachmentDto('https://example.com/img.png', null, ['media_type' => 'image/png']);
        $block = $dto->getContentBlock();

        $this->assertInstanceOf(ImageContent::class, $block);
        $this->assertSame('https://example.com/img.png', $block->content);
        $this->assertSame(SourceType::URL, $block->sourceType);
        $this->assertSame('image/png', $block->mediaType);
    }

    public function testImageAttachmentCreatesImageContentBlockFromDataUri(): void
    {
        $dto = new ImageAttachmentDto('data:image/png;base64,QUJD', null, []);
        $block = $dto->getContentBlock();

        $this->assertInstanceOf(ImageContent::class, $block);
        $this->assertSame(SourceType::BASE64, $block->sourceType);
        $this->assertSame('image/png', $block->mediaType);
        $this->assertSame('QUJD', $block->content);
    }

    public function testTextFileAttachmentReadsLocalFileAndCreatesFileContentBlock(): void
    {
        $tmp = sys_get_temp_dir() . '/neuronapp_text_attachment_' . uniqid() . '.txt';
        file_put_contents($tmp, "hello\nworld");

        try {
            $dto = new TextFileAttachmentDto($tmp, 'doc');
            $block = $dto->getContentBlock();

            $this->assertInstanceOf(FileContent::class, $block);
            $this->assertSame(SourceType::BASE64, $block->sourceType);
            $this->assertSame(basename($tmp), $block->filename);
            $this->assertSame(base64_encode("hello\nworld"), $block->content);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImageFileAttachmentReadsLocalFileAndCreatesImageContentBlock(): void
    {
        // Minimal PNG header bytes to ensure mime detection is likely image/png.
        $png = "\x89PNG\r\n\x1a\n" . "TEST";
        $tmp = sys_get_temp_dir() . '/neuronapp_image_attachment_' . uniqid() . '.png';
        file_put_contents($tmp, $png);

        try {
            $dto = new ImageFileAttachmentDto($tmp, 'img');
            $block = $dto->getContentBlock();

            $this->assertInstanceOf(ImageContent::class, $block);
            $this->assertSame(SourceType::BASE64, $block->sourceType);
            $this->assertSame(base64_encode($png), $block->content);
            // mediaType может определяться системой; проверим, что не пустой если finfo сработал
            $this->assertNotNull($block->mediaType);
        } finally {
            @unlink($tmp);
        }
    }
}

