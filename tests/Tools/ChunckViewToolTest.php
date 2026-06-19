<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\tools\ChunckViewTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_decode;
use function mkdir;
use function str_repeat;

use const DIRECTORY_SEPARATOR;

/**
 * Тесты для {@see ChunckViewTool}.
 *
 * Проверяют 1-based нумерацию строк, чтение чанков и граничные условия.
 */
final class ChunckViewToolTest extends TestCase
{
    /**
     * @var string
     */
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chunk_view_tool_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    /**
     * Без start_line чтение начинается с первой строки (1-based).
     */
    public function testReadsFromFirstLineByDefault(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "alpha\nbeta\ngamma");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('file.txt'), true);

        $this->assertSame(1, $data['startLine']);
        $this->assertSame(3, $data['endLine']);
        $this->assertSame(3, $data['totalLines']);
        $this->assertStringContainsString('alpha', $data['chunk']);
    }

    /**
     * start_line=3 возвращает чанк с третьей строки.
     */
    public function testReadsFromExplicitStartLine(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "one\ntwo\nthree\nfour");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('file.txt', start_line: 3), true);

        $this->assertSame(3, $data['startLine']);
        $this->assertSame(4, $data['endLine']);
        $this->assertSame("three\nfour", $data['chunk']);
    }

    /**
     * Пагинация: второй чанк через start_line = endLine + 1.
     */
    public function testPaginationUsesEndLinePlusOne(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "a\nb\nc\nd");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $first = json_decode($tool->__invoke('file.txt', start_line: 1, lines: 2), true);
        $second = json_decode(
            $tool->__invoke('file.txt', start_line: $first['endLine'] + 1, lines: 2),
            true
        );

        $this->assertSame(1, $first['startLine']);
        $this->assertSame(2, $first['endLine']);
        $this->assertSame(3, $second['startLine']);
        $this->assertSame(4, $second['endLine']);
        $this->assertSame("c\nd", $second['chunk']);
    }

    /**
     * start_line=0 нормализуется к 1.
     */
    public function testZeroStartLineNormalizesToOne(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "first\nsecond");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('file.txt', start_line: 0), true);

        $this->assertSame(1, $data['startLine']);
        $this->assertSame(2, $data['endLine']);
    }

    /**
     * Отрицательный start_line нормализуется к 1.
     */
    public function testNegativeStartLineNormalizesToOne(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "only");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('file.txt', start_line: -5), true);

        $this->assertSame(1, $data['startLine']);
        $this->assertSame('only', trim($data['chunk']));
    }

    /**
     * start_line за пределами файла возвращает ошибку.
     */
    public function testStartLineBeyondFileReturnsError(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "line1\nline2");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('file.txt', start_line: 100), true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Параметр lines ограничивает число строк в чанке.
     */
    public function testLinesLimitRestrictsChunk(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "1\n2\n3\n4\n5");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('file.txt', start_line: 1, lines: 2), true);

        $this->assertSame(1, $data['startLine']);
        $this->assertSame(2, $data['endLine']);
        $this->assertSame("1\n2", $data['chunk']);
    }

    /**
     * max_chars ограничивает размер чанка по символам.
     */
    public function testMaxCharsLimitRestrictsChunk(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "short\nvery_long_line_content");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('file.txt', start_line: 1, max_chars: 10), true);

        $this->assertSame(1, $data['startLine']);
        $this->assertSame(1, $data['endLine']);
        $this->assertSame('short', trim($data['chunk']));
    }

    /**
     * max_chars меньше первой строки даёт пустой чанк и endLine=0.
     */
    public function testMaxCharsSmallerThanFirstLineReturnsEmptyChunk(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "abcdefghij");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('file.txt', start_line: 1, max_chars: 3), true);

        $this->assertSame('', $data['chunk']);
        $this->assertSame(1, $data['startLine']);
        $this->assertSame(0, $data['endLine']);
    }

    /**
     * Несуществующий файл возвращает ошибку.
     */
    public function testNonExistentFileReturnsError(): void
    {
        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('missing.txt'), true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Бинарный файл отклоняется.
     */
    public function testBinaryFileReturnsError(): void
    {
        file_put_contents($this->tempDir . '/image.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 50));

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('image.png'), true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * totalLength отражает суммарное число символов в файле.
     */
    public function testReportsTotalLinesAndTotalLength(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "ab\ncd");

        $tool = new ChunckViewTool(basePath: $this->tempDir);
        $data = json_decode($tool->__invoke('file.txt'), true);

        $this->assertSame(2, $data['totalLines']);
        $this->assertGreaterThan(0, $data['totalLength']);
    }

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
