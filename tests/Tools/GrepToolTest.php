<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\tools\GrepTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_decode;
use function mkdir;

use const DIRECTORY_SEPARATOR;

/**
 * Тесты для {@see GrepTool}.
 *
 * Проверяют корректность поиска текста и регулярных выражений внутри файлов:
 * - поиск по regex-паттерну с номерами строк
 * - поиск по regex с группами (function\s+\w+)
 * - поиск в конкретном файле (path)
 * - фильтрация по типу файлов (include)
 * - пустые результаты при отсутствии совпадений
 * - усечение при превышении maxMatches
 * - обработку пустого/невалидного паттерна
 * - пропуск бинарных файлов
 * - обработку несуществующего пути
 * - рекурсивный поиск в поддиректориях
 * - автоматическое оборачивание текста в regex
 * - фильтрацию по excludePatterns
 * - корректность работы сеттеров
 */
final class GrepToolTest extends TestCase
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
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'grep_tool_test_' . uniqid();
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
     * Проверяет поиск простого текста с корректными номерами строк.
     *
     * «Hello» встречается в строках 1 и 3, ожидается 2 совпадения.
     *
     * @return void
     */
    public function testFindsTextInFiles(): void
    {
        file_put_contents($this->tempDir . '/hello.txt', "Hello World\nGoodbye World\nHello Again");

        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('/Hello/');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('matches', $data);
        $this->assertSame(2, $data['totalMatches']);
        $this->assertSame(1, $data['filesSearched']);
        $this->assertSame(1, $data['matches'][0]['lineNumber']);
        $this->assertSame(3, $data['matches'][1]['lineNumber']);
    }

    /**
     * Проверяет поиск по regex-паттерну с группами (function\s+\w+).
     *
     * Ожидается нахождение двух определений функций.
     *
     * @return void
     */
    public function testFindsRegexPattern(): void
    {
        file_put_contents($this->tempDir . '/code.php', "<?php\nfunction foo() {}\nfunction bar() {}\nclass Baz {}");

        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('/function\s+\w+/');
        $data = json_decode($json, true);

        $this->assertSame(2, $data['totalMatches']);
        $this->assertStringContainsString('function foo', $data['matches'][0]['matchText']);
        $this->assertStringContainsString('function bar', $data['matches'][1]['matchText']);
    }

    /**
     * Проверяет поиск в конкретном файле (параметр path).
     *
     * При указании файла a.txt поиск не должен затрагивать b.txt.
     *
     * @return void
     */
    public function testSearchInSpecificFile(): void
    {
        file_put_contents($this->tempDir . '/a.txt', "target line\nother line");
        file_put_contents($this->tempDir . '/b.txt', "target line in b");

        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('/target/', 'a.txt');
        $data = json_decode($json, true);

        $this->assertSame(1, $data['totalMatches']);
        $this->assertSame(1, $data['filesSearched']);
    }

    /**
     * Проверяет фильтрацию по типу файлов (параметр include).
     *
     * При include='*.php' файл .txt должен быть проигнорирован.
     *
     * @return void
     */
    public function testIncludeFilterRestrictsFileTypes(): void
    {
        file_put_contents($this->tempDir . '/code.php', 'search_term_here');
        file_put_contents($this->tempDir . '/data.txt', 'search_term_here');

        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('/search_term_here/', null, '*.php');
        $data = json_decode($json, true);

        $this->assertSame(1, $data['totalMatches']);
        $this->assertStringContainsString('.php', $data['matches'][0]['filePath']);
    }

    /**
     * Проверяет, что при отсутствии совпадений возвращается пустой массив matches.
     *
     * @return void
     */
    public function testNoMatchesReturnsEmptyList(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "no matches here");

        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('/zzz_not_found/');
        $data = json_decode($json, true);

        $this->assertSame(0, $data['totalMatches']);
        $this->assertCount(0, $data['matches']);
    }

    /**
     * Проверяет усечение результатов при превышении maxMatches.
     *
     * Из 100 строк-совпадений при maxMatches=5 должно вернуться 5 с truncated=true.
     *
     * @return void
     */
    public function testMaxMatchesTruncation(): void
    {
        $lines = '';
        for ($i = 0; $i < 100; $i++) {
            $lines .= "match_line_{$i}\n";
        }
        file_put_contents($this->tempDir . '/big.txt', $lines);

        $tool = new GrepTool(basePath: $this->tempDir, maxMatches: 5, excludePatterns: []);
        $json = $tool->__invoke('/match_line/');
        $data = json_decode($json, true);

        $this->assertTrue($data['truncated']);
        $this->assertCount(5, $data['matches']);
    }

    /**
     * Проверяет, что пустой паттерн возвращает ошибку (ключ error).
     *
     * @return void
     */
    public function testInvalidRegexReturnsError(): void
    {
        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Проверяет, что бинарные файлы (.png с NUL-байтами) пропускаются.
     *
     * @return void
     */
    public function testSkipsBinaryFiles(): void
    {
        file_put_contents($this->tempDir . '/image.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 100));

        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('/PNG/');
        $data = json_decode($json, true);

        $this->assertSame(0, $data['totalMatches']);
        $this->assertSame(0, $data['filesSearched']);
    }

    /**
     * Проверяет, что несуществующий путь (path) возвращает ошибку.
     *
     * @return void
     */
    public function testNonExistentPathReturnsError(): void
    {
        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('/test/', 'nonexistent_file.txt');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Проверяет рекурсивный поиск в поддиректориях.
     *
     * Файлы в корне и в поддиректории должны быть просканированы.
     *
     * @return void
     */
    public function testRecursiveSearchInSubdirectories(): void
    {
        mkdir($this->tempDir . '/sub', 0755);
        file_put_contents($this->tempDir . '/root.txt', 'keyword');
        file_put_contents($this->tempDir . '/sub/child.txt', 'keyword found');

        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('/keyword/');
        $data = json_decode($json, true);

        $this->assertSame(2, $data['totalMatches']);
        $this->assertSame(2, $data['filesSearched']);
    }

    /**
     * Проверяет, что простой текст (без regex-разделителей) автоматически оборачивается.
     *
     * Строка «Hello World» должна быть найдена как есть.
     *
     * @return void
     */
    public function testPlainTextSearchAutoWrapped(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "Hello World\nfoo bar");

        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('Hello World');
        $data = json_decode($json, true);

        $this->assertSame(1, $data['totalMatches']);
    }

    /**
     * Проверяет, что excludePatterns корректно исключает указанные директории.
     *
     * Файл в node_modules должен быть пропущен, а файл в src — найден.
     *
     * @return void
     */
    public function testExcludePatternsFilterDirectories(): void
    {
        mkdir($this->tempDir . '/node_modules', 0755);
        mkdir($this->tempDir . '/src', 0755);
        file_put_contents($this->tempDir . '/node_modules/dep.js', 'findme');
        file_put_contents($this->tempDir . '/src/app.js', 'findme');

        $tool = new GrepTool(basePath: $this->tempDir, excludePatterns: ['node_modules']);
        $json = $tool->__invoke('/findme/');
        $data = json_decode($json, true);

        $this->assertSame(1, $data['totalMatches']);
        $this->assertStringContainsString('src', $data['matches'][0]['filePath']);
    }

    /**
     * Проверяет, что сеттеры корректно обновляют свойства инструмента.
     *
     * @return void
     */
    public function testSettersUpdateProperties(): void
    {
        $tool = new GrepTool();
        $tool->setBasePath($this->tempDir)
             ->setMaxMatches(10)
             ->setMaxFileSize(500)
             ->setExcludePatterns([])
             ->setContextLines(2);

        file_put_contents($this->tempDir . '/test.txt', "line1\nline2");
        $json = $tool->__invoke('/line/');
        $data = json_decode($json, true);

        $this->assertSame(2, $data['totalMatches']);
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
