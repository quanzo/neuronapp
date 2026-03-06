<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\tools\GlobTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_decode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Тесты для {@see GlobTool}.
 *
 * Проверяют корректность поиска файлов по glob-шаблонам, включая:
 * - поиск по простому шаблону (*.txt)
 * - рекурсивный поиск (**\/*.php)
 * - пустые результаты при отсутствии совпадений
 * - фильтрацию по excludePatterns (исключение .git и т.д.)
 * - усечение результатов при превышении maxResults
 * - обработку несуществующей базовой директории
 * - обработку пустой директории
 * - корректность работы сеттеров
 *
 * Каждый тест создаёт временную директорию с тестовыми файлами
 * и удаляет её после завершения.
 */
final class GlobToolTest extends TestCase
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
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'glob_tool_test_' . uniqid();
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
     * Проверяет, что инструмент находит файлы по простому шаблону (*.txt).
     *
     * Ожидается, что из трёх файлов (два .txt и один .php) будут найдены только два .txt.
     *
     * @return void
     */
    public function testFindsFilesByPattern(): void
    {
        file_put_contents($this->tempDir . '/file1.txt', 'hello');
        file_put_contents($this->tempDir . '/file2.txt', 'world');
        file_put_contents($this->tempDir . '/file3.php', '<?php');

        $tool = new GlobTool(basePath: $this->tempDir);
        $json = $tool->__invoke('*.txt');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('files', $data);
        $this->assertCount(2, $data['files']);
        $this->assertContains('file1.txt', $data['files']);
        $this->assertContains('file2.txt', $data['files']);
        $this->assertFalse($data['truncated']);
        $this->assertSame(2, $data['totalFound']);
    }

    /**
     * Проверяет рекурсивный поиск с паттерном **\/*.php.
     *
     * Файлы распределены по вложенным директориям, все три .php должны быть найдены,
     * а .txt — пропущен.
     *
     * @return void
     */
    public function testRecursiveSearch(): void
    {
        mkdir($this->tempDir . '/sub/deep', 0755, true);
        file_put_contents($this->tempDir . '/root.php', '<?php');
        file_put_contents($this->tempDir . '/sub/child.php', '<?php');
        file_put_contents($this->tempDir . '/sub/deep/nested.php', '<?php');
        file_put_contents($this->tempDir . '/sub/deep/data.txt', 'text');

        $tool = new GlobTool(basePath: $this->tempDir, excludePatterns: []);
        $json = $tool->__invoke('**/*.php');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('files', $data);
        $this->assertSame(3, $data['totalFound']);
        $this->assertContains('root.php', $data['files']);
        $this->assertContains('sub' . DIRECTORY_SEPARATOR . 'child.php', $data['files']);
        $this->assertContains('sub' . DIRECTORY_SEPARATOR . 'deep' . DIRECTORY_SEPARATOR . 'nested.php', $data['files']);
    }

    /**
     * Проверяет, что при отсутствии совпадений возвращается пустой массив files.
     *
     * @return void
     */
    public function testNoMatchesReturnsEmptyList(): void
    {
        file_put_contents($this->tempDir . '/file.txt', 'data');

        $tool = new GlobTool(basePath: $this->tempDir);
        $json = $tool->__invoke('*.xyz');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('files', $data);
        $this->assertCount(0, $data['files']);
        $this->assertSame(0, $data['totalFound']);
    }

    /**
     * Проверяет, что excludePatterns корректно исключает указанные директории.
     *
     * Файл в .git должен быть пропущен, а файл в src — найден.
     *
     * @return void
     */
    public function testExcludePatternsFilterDirectories(): void
    {
        mkdir($this->tempDir . '/.git', 0755);
        mkdir($this->tempDir . '/src', 0755);
        file_put_contents($this->tempDir . '/.git/config', 'data');
        file_put_contents($this->tempDir . '/src/app.php', '<?php');

        $tool = new GlobTool(basePath: $this->tempDir, excludePatterns: ['.git']);
        $json = $tool->__invoke('**/*.php');
        $data = json_decode($json, true);

        $this->assertSame(1, $data['totalFound']);
        $this->assertContains('src' . DIRECTORY_SEPARATOR . 'app.php', $data['files']);
    }

    /**
     * Проверяет усечение результатов при превышении maxResults.
     *
     * Из 10 файлов при maxResults=3 должно вернуться 3 файла
     * с truncated=true и totalFound=10.
     *
     * @return void
     */
    public function testMaxResultsTruncation(): void
    {
        for ($i = 0; $i < 10; $i++) {
            file_put_contents($this->tempDir . "/file{$i}.txt", "content{$i}");
        }

        $tool = new GlobTool(basePath: $this->tempDir, maxResults: 3);
        $json = $tool->__invoke('*.txt');
        $data = json_decode($json, true);

        $this->assertTrue($data['truncated']);
        $this->assertSame(10, $data['totalFound']);
        $this->assertCount(3, $data['files']);
    }

    /**
     * Проверяет, что несуществующая basePath возвращает ошибку (ключ error в JSON).
     *
     * @return void
     */
    public function testNonExistentBaseDirectoryReturnsError(): void
    {
        $tool = new GlobTool(basePath: '/non/existent/path');
        $json = $tool->__invoke('*.txt');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Проверяет, что пустая директория возвращает пустой массив files.
     *
     * @return void
     */
    public function testEmptyDirectoryReturnsEmptyFiles(): void
    {
        $tool = new GlobTool(basePath: $this->tempDir);
        $json = $tool->__invoke('*');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('files', $data);
        $this->assertCount(0, $data['files']);
    }

    /**
     * Проверяет, что сеттеры корректно обновляют свойства инструмента.
     *
     * Вызывает все сеттеры по цепочке и убеждается, что инструмент
     * работает с обновлёнными настройками.
     *
     * @return void
     */
    public function testSettersUpdateProperties(): void
    {
        $tool = new GlobTool();
        $tool->setBasePath($this->tempDir)
             ->setMaxResults(5)
             ->setExcludePatterns(['.git'])
             ->setFollowSymlinks(true)
             ->setRespectGitignore(true);

        file_put_contents($this->tempDir . '/a.txt', 'data');
        $json = $tool->__invoke('*.txt');
        $data = json_decode($json, true);

        $this->assertCount(1, $data['files']);
    }

    /**
     * Рекурсивно удаляет директорию и всё её содержимое.
     *
     * Используется в tearDown() для очистки тестовых данных.
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
