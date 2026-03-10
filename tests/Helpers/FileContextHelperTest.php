<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\attachments\AttachmentDto;
use app\modules\neuron\helpers\FileContextHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see FileContextHelper}.
 *
 * FileContextHelper отвечает за:
 *  - извлечение путей к файлам из текста по синтаксису с символом '@';
 *  - построение вложений (AttachmentDto) по найденным путям с учётом настроек
 *    context_files.enabled и context_files.max_total_size в {@see ConfigurationApp}.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\FileContextHelper}
 */
class FileContextHelperTest extends TestCase
{
    /**
     * Пустой текст — пустой список путей.
     */
    public function testExtractFilePathsFromEmptyBodyReturnsEmpty(): void
    {
        $this->assertSame([], FileContextHelper::extractFilePathsFromBody(''));
    }

    /**
     * Извлекаются пути из начала строки и после пробела.
     */
    public function testExtractFilePathsFromBodyFindsPaths(): void
    {
        $body = "@file1.txt\nТекст @dir/file2.md ещё текст\nemail@example.com\n";

        $paths = FileContextHelper::extractFilePathsFromBody($body);

        $this->assertSame(['file1.txt', 'dir/file2.md'], $paths);
    }

    /**
     * Включённый режим context_files и существующий файл приводят к созданию вложения.
     */
    public function testBuildContextAttachmentsCreatesAttachmentWhenEnabled(): void
    {
        $tmpDir = sys_get_temp_dir() . '/neuronapp_file_context_' . uniqid();
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/context.txt', 'content');

        try {
            $dirPriority = new DirPriority([$tmpDir]);

            $configApp = $this->createMock(ConfigurationApp::class);
            $configApp->method('getDirPriority')->willReturn($dirPriority);
            $configApp->method('get')
                ->willReturnMap([
                    ['context_files.enabled', false, true],
                    ['context_files.max_total_size', 1048576, 1048576],
                ]);

            $body = '@context.txt';

            $result = FileContextHelper::buildContextAttachments($body, $configApp);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('attachments', $result);
            $this->assertArrayHasKey('totalSize', $result);
            $this->assertGreaterThan(0, $result['totalSize']);
            $this->assertNotSame([], $result['attachments']);
            $this->assertInstanceOf(AttachmentDto::class, $result['attachments'][0]);
        } finally {
            if (is_dir($tmpDir)) {
                foreach (scandir($tmpDir) ?: [] as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }
                    @unlink($tmpDir . '/' . $item);
                }
                @rmdir($tmpDir);
            }
        }
    }

    /**
     * При отключённой опции context_files.enabled вложения не создаются.
     */
    public function testBuildContextAttachmentsDisabledReturnsEmpty(): void
    {
        $dirPriority = new DirPriority([sys_get_temp_dir()]);

        $configApp = $this->createMock(ConfigurationApp::class);
        $configApp->method('getDirPriority')->willReturn($dirPriority);
        $configApp->method('get')
            ->willReturnMap([
                ['context_files.enabled', false, false],
                ['context_files.max_total_size', 1048576, 1048576],
            ]);

        $body = '@any.txt';

        $result = FileContextHelper::buildContextAttachments($body, $configApp);

        $this->assertSame([], $result['attachments']);
        $this->assertSame(0, $result['totalSize']);
    }
}
