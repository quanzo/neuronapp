<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\classes\command\SimpleMessageCommand;
use app\modules\neuron\classes\command\TodolistCommand;
use app\modules\neuron\classes\dto\attachments\ImageFileAttachmentDto;
use app\modules\neuron\classes\dto\attachments\TextFileAttachmentDto;
use app\modules\neuron\helpers\AttachmentHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

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
            $output = new BufferedOutput();
            $attachments = AttachmentHelper::buildAttachmentsFromPaths([$textFile, $imageFile], $output);

            $this->assertIsArray($attachments);
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

        $output = new BufferedOutput();
        $attachments = AttachmentHelper::buildAttachmentsFromPaths([$missing], $output);

        $this->assertNull($attachments);
        $display = $output->fetch();
        $this->assertStringContainsString('не найден или недоступен', $display);
    }
}

