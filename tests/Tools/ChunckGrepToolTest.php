<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\tools\ChunckGrepTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * Тесты для {@see ChunckGrepTool}.
 *
 * Проверяют:
 * - поиск по обычной строке
 * - поиск по regex
 * - лимит по суммарному размеру результата
 * - обработку некорректного max_chars
 */
final class ChunckGrepToolTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chunk_grep_tool_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    /**
     * Проверяет поиск по обычной строке (не regex).
     */
    public function testFindsChunksByPlainString(): void
    {
        $file = $this->tempDir . '/doc.md';
        file_put_contents($file, implode("\n", [
            '# Title',
            '',
            'Intro text.',
            '',
            'Anchor: FIND_ME',
            'More context line.',
            '',
            'Tail.',
        ]));

        $tool = new ChunckGrepTool(basePath: $this->tempDir);
        $json = $tool->__invoke('doc.md', 'FIND_ME', 5000);
        $data = json_decode($json, true);

        $this->assertSame('doc.md', $data['filePath']);
        $this->assertSame('FIND_ME', $data['query']);
        $this->assertSame(1, $data['totalChunks']);
        $this->assertStringContainsString('Anchor: FIND_ME', $data['chunks'][0]['text']);
    }

    /**
     * Проверяет поиск по regex (с разделителями).
     */
    public function testFindsChunksByRegex(): void
    {
        $file = $this->tempDir . '/doc.md';
        file_put_contents($file, "a\nb\nAnchor: FIND_ME\nc");

        $tool = new ChunckGrepTool(basePath: $this->tempDir);
        $json = $tool->__invoke('doc.md', '/^Anchor:/u', 5000);
        $data = json_decode($json, true);

        $this->assertSame(1, $data['totalChunks']);
        $this->assertStringContainsString('Anchor: FIND_ME', $data['chunks'][0]['text']);
    }

    /**
     * Проверяет, что max_chars ограничивает суммарный размер результата.
     */
    public function testMaxCharsLimitsTotalResult(): void
    {
        $file = $this->tempDir . '/doc.md';
        file_put_contents($file, implode("\n", [
            'Anchor: FIND_ME',
            '',
            str_repeat('x', 300),
            '',
            'Anchor: FIND_ME',
            '',
            str_repeat('y', 300),
        ]));

        $tool = new ChunckGrepTool(basePath: $this->tempDir);
        $json = $tool->__invoke('doc.md', 'FIND_ME', 200);
        $data = json_decode($json, true);

        $this->assertLessThanOrEqual(200, $data['totalChars']);
    }

    /**
     * Проверяет обработку некорректного max_chars.
     */
    public function testInvalidMaxCharsReturnsError(): void
    {
        $file = $this->tempDir . '/doc.md';
        file_put_contents($file, "Anchor: FIND_ME\n");

        $tool = new ChunckGrepTool(basePath: $this->tempDir);
        $json = $tool->__invoke('doc.md', 'FIND_ME', 0);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
