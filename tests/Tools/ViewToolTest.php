<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\tools\ViewTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_decode;
use function mkdir;

use const DIRECTORY_SEPARATOR;

/**
 * Тесты для {@see ViewTool}.
 *
 * Проверяют корректность чтения файлов с нумерацией строк, включая:
 * - полное чтение файла с нумерацией строк
 * - частичное чтение по диапазону start_line/end_line
 * - усечение при превышении maxLines
 * - обработку несуществующего файла
 * - отклонение бинарного файла
 * - защиту от path-traversal (../../etc/passwd)
 * - отклонение файла, превышающего maxFileSize
 * - обработку start_line за пределами файла
 * - чтение пустого файла
 * - нормализацию отрицательного start_line к 1
 * - корректность работы сеттеров
 */
final class ViewToolTest extends TestCase
{
    /**
     * Путь к временной директории, создаваемой для каждого теста.
     *
     * @var string
     */
    private string $tempDir;

    /**
     * Создаёт уникальную временную директорию перед каждым тестом.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'view_tool_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    /**
     * Удаляет временную директорию и всё её содержимое после каждого теста.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    /**
     * Проверяет полное чтение файла с нумерацией строк.
     *
     * Ожидается формат «N|содержимое» для каждой строки, startLine=1, endLine=3.
     *
     * @return void
     */
    public function testReadsFileWithLineNumbers(): void
    {
        file_put_contents($this->tempDir . '/test.txt', "line1\nline2\nline3");

        $tool = new ViewTool(basePath: $this->tempDir);
        $json = $tool->__invoke('test.txt');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('content', $data);
        $this->assertSame(1, $data['startLine']);
        $this->assertSame(3, $data['endLine']);
        $this->assertSame(3, $data['totalLines']);
        $this->assertFalse($data['truncated']);
        $this->assertStringContainsString('1|line1', $data['content']);
        $this->assertStringContainsString('3|line3', $data['content']);
    }

    /**
     * Проверяет частичное чтение файла по диапазону строк (5-10 из 20).
     *
     * Строки 4 и 11 не должны присутствовать в результате.
     *
     * @return void
     */
    public function testReadsLineRange(): void
    {
        $content = implode("\n", array_map(fn(int $i) => "Line number {$i}", range(1, 20)));
        file_put_contents($this->tempDir . '/lines.txt', $content);

        $tool = new ViewTool(basePath: $this->tempDir);
        $json = $tool->__invoke('lines.txt', 5, 10);
        $data = json_decode($json, true);

        $this->assertSame(5, $data['startLine']);
        $this->assertSame(10, $data['endLine']);
        $this->assertSame(20, $data['totalLines']);
        $this->assertStringContainsString('Line number 5', $data['content']);
        $this->assertStringContainsString('Line number 10', $data['content']);
        $this->assertStringNotContainsString('Line number 4', $data['content']);
        $this->assertStringNotContainsString('Line number 11', $data['content']);
    }

    /**
     * Проверяет усечение содержимого при превышении maxLines.
     *
     * Файл из 50 строк при maxLines=10 должен вернуть 10 строк с truncated=true.
     *
     * @return void
     */
    public function testTruncatesAtMaxLines(): void
    {
        $content = implode("\n", array_map(fn(int $i) => "line{$i}", range(1, 50)));
        file_put_contents($this->tempDir . '/big.txt', $content);

        $tool = new ViewTool(basePath: $this->tempDir, maxLines: 10);
        $json = $tool->__invoke('big.txt');
        $data = json_decode($json, true);

        $this->assertTrue($data['truncated']);
        $this->assertSame(1, $data['startLine']);
        $this->assertSame(10, $data['endLine']);
        $this->assertSame(50, $data['totalLines']);
    }

    /**
     * Проверяет, что несуществующий файл возвращает ошибку.
     *
     * @return void
     */
    public function testNonExistentFileReturnsError(): void
    {
        $tool = new ViewTool(basePath: $this->tempDir);
        $json = $tool->__invoke('nope.txt');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Проверяет, что бинарный файл (.png с NUL-байтами) отклоняется.
     *
     * @return void
     */
    public function testBinaryFileReturnsError(): void
    {
        file_put_contents($this->tempDir . '/image.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 100));

        $tool = new ViewTool(basePath: $this->tempDir);
        $json = $tool->__invoke('image.png');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('бинарным', $data['error']);
    }

    /**
     * Проверяет защиту от path-traversal (../../etc/passwd).
     *
     * Путь выходит за пределы basePath и должен быть отклонён.
     *
     * @return void
     */
    public function testPathTraversalReturnsError(): void
    {
        $tool = new ViewTool(basePath: $this->tempDir);
        $json = $tool->__invoke('../../etc/passwd');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Проверяет отклонение файла, превышающего maxFileSize.
     *
     * Файл 200 байт при maxFileSize=100 должен быть отклонён.
     *
     * @return void
     */
    public function testFileTooLargeReturnsError(): void
    {
        file_put_contents($this->tempDir . '/huge.txt', str_repeat('x', 200));

        $tool = new ViewTool(basePath: $this->tempDir, maxFileSize: 100);
        $json = $tool->__invoke('huge.txt');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('большой', $data['error']);
    }

    /**
     * Проверяет, что start_line за пределами файла возвращает ошибку.
     *
     * Файл из 2 строк, start_line=100 — ошибка.
     *
     * @return void
     */
    public function testStartLineBeyondFileReturnsError(): void
    {
        file_put_contents($this->tempDir . '/short.txt', "line1\nline2");

        $tool = new ViewTool(basePath: $this->tempDir);
        $json = $tool->__invoke('short.txt', 100);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Проверяет, что пустой файл корректно читается (1 строка, пустое содержимое).
     *
     * @return void
     */
    public function testEmptyFileReturnsContent(): void
    {
        file_put_contents($this->tempDir . '/empty.txt', '');

        $tool = new ViewTool(basePath: $this->tempDir);
        $json = $tool->__invoke('empty.txt');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('content', $data);
        $this->assertSame(1, $data['totalLines']);
    }

    /**
     * Проверяет, что отрицательный start_line нормализуется к 1.
     *
     * @return void
     */
    public function testNegativeStartLineClampedToOne(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "a\nb\nc");

        $tool = new ViewTool(basePath: $this->tempDir);
        $json = $tool->__invoke('file.txt', -5, 2);
        $data = json_decode($json, true);

        $this->assertSame(1, $data['startLine']);
        $this->assertSame(2, $data['endLine']);
    }

    /**
     * Проверяет, что сеттеры корректно обновляют свойства инструмента.
     *
     * @return void
     */
    public function testSettersUpdateProperties(): void
    {
        $tool = new ViewTool();
        $tool->setBasePath($this->tempDir)
             ->setMaxFileSize(1024)
             ->setMaxLines(100)
             ->setEncoding('UTF-8');

        file_put_contents($this->tempDir . '/s.txt', 'data');
        $json = $tool->__invoke('s.txt');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('content', $data);
    }

    /**
     * Рекурсивно удаляет директорию и всё её содержимое.
     *
     * @param string $dir Путь к директории для удаления
     *
     * @return void
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
