<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\command\SimpleMessageCommand;
use app\modules\neuron\command\TodolistCommand;
use app\modules\neuron\classes\dto\attachments\ImageFileAttachmentDto;
use app\modules\neuron\classes\dto\attachments\TextFileAttachmentDto;
use app\modules\neuron\helpers\AttachmentHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputOption;

/**
 * Тесты CLI-опций файлов и вспомогательной логики построения вложений в консольных командах.
 */
final class CommandAttachmentsOptionTest extends TestCase
{
    public function testSimpleMessageCommandHasFileOption(): void
    {
        $command = new SimpleMessageCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('file'));
        /** @var InputOption $opt */
        $opt = $definition->getOption('file');
        $this->assertTrue($opt->isArray());
        $this->assertTrue($opt->isValueRequired());
        $this->assertSame('f', $opt->getShortcut());
    }

    public function testTodolistCommandHasFileOption(): void
    {
        $command = new TodolistCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('file'));
        /** @var InputOption $opt */
        $opt = $definition->getOption('file');
        $this->assertTrue($opt->isArray());
        $this->assertTrue($opt->isValueRequired());
        $this->assertSame('f', $opt->getShortcut());
    }

    public function testBuildAttachmentsFromPathsCreatesTextAndImageDtos(): void
    {
        $textFile = sys_get_temp_dir() . '/neuronapp_cmd_text_' . uniqid() . '.txt';
        $imageFile = sys_get_temp_dir() . '/neuronapp_cmd_image_' . uniqid() . '.png';

        file_put_contents($textFile, 'text');
        file_put_contents($imageFile, "\x89PNG\r\n\x1a\n");

        try {
            $result = AttachmentHelper::buildAttachmentsFromPaths([$textFile, $imageFile]);

            $this->assertFalse($result->isError());
            $attachments = $result->getAttachments();
            $this->assertCount(2, $attachments);
            $this->assertInstanceOf(TextFileAttachmentDto::class, $attachments[0]);
            $this->assertInstanceOf(ImageFileAttachmentDto::class, $attachments[1]);
        } finally {
            @unlink($textFile);
            @unlink($imageFile);
        }
    }

    public function testBuildAttachmentsFromPathsFailsOnMissingFile(): void
    {
        $missing = 'definitely_non_existing_file_' . uniqid() . '.txt';

        $result = AttachmentHelper::buildAttachmentsFromPaths([$missing]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('не найден или недоступен', $result->getErrorMessage());
        $this->assertSame([], $result->getAttachments());
    }
}
