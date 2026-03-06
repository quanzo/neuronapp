<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\tools\EditTool;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function mkdir;

use const DIRECTORY_SEPARATOR;

/**
 * Тесты для {@see EditTool}.
 *
 * Проверяют корректность редактирования файлов, включая:
 * - замену уникального вхождения old_string
 * - ошибку при множественных вхождениях (>1)
 * - ошибку при отсутствии вхождения (0)
 * - создание нового файла (createIfNotExists=true, old_string='')
 * - создание файла в несуществующей поддиректории
 * - отказ при createIfNotExists=false
 * - ошибку при непустом old_string для нового файла
 * - создание резервной копии (.bak)
 * - защиту от path-traversal (../../etc/passwd)
 * - отклонение файла, превышающего maxFileSize
 * - ошибку при пустом old_string для существующего файла
 * - корректность работы сеттеров
 * - сохранение остального содержимого файла при замене
 */
final class EditToolTest extends TestCase
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
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edit_tool_test_' . uniqid();
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
     * Проверяет замену уникального вхождения old_string на new_string.
     *
     * Остальная часть файла должна остаться нетронутой.
     *
     * @return void
     */
    public function testReplacesUniqueOccurrence(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "Hello World\nGoodbye World");

        $tool = new EditTool(basePath: $this->tempDir, createBackup: false);
        $json = $tool->__invoke('file.txt', 'Hello World', 'Hi World');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['replacements']);

        $content = file_get_contents($this->tempDir . '/file.txt');
        $this->assertStringContainsString('Hi World', $content);
        $this->assertStringContainsString('Goodbye World', $content);
    }

    /**
     * Проверяет, что множественные вхождения old_string (3 шт.) вызывают ошибку.
     *
     * Замена должна быть отклонена с сообщением «3 раз».
     *
     * @return void
     */
    public function testMultipleOccurrencesReturnsError(): void
    {
        file_put_contents($this->tempDir . '/dup.txt', "Hello Hello Hello");

        $tool = new EditTool(basePath: $this->tempDir, createBackup: false);
        $json = $tool->__invoke('dup.txt', 'Hello', 'Hi');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('3 раз', $data['message']);
    }

    /**
     * Проверяет, что отсутствие вхождения old_string вызывает ошибку.
     *
     * @return void
     */
    public function testNoOccurrenceReturnsError(): void
    {
        file_put_contents($this->tempDir . '/file.txt', "nothing here");

        $tool = new EditTool(basePath: $this->tempDir, createBackup: false);
        $json = $tool->__invoke('file.txt', 'nonexistent_string', 'replacement');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('не найден', $data['message']);
    }

    /**
     * Проверяет создание нового файла при createIfNotExists=true и пустом old_string.
     *
     * @return void
     */
    public function testCreatesNewFileWhenEnabled(): void
    {
        $tool = new EditTool(basePath: $this->tempDir, createIfNotExists: true);
        $json = $tool->__invoke('new_file.txt', '', 'New file content');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertStringContainsString('создан', $data['message']);

        $content = file_get_contents($this->tempDir . '/new_file.txt');
        $this->assertSame('New file content', $content);
    }

    /**
     * Проверяет создание файла в несуществующей поддиректории.
     *
     * Промежуточные директории должны быть созданы автоматически.
     *
     * @return void
     */
    public function testCreatesNewFileInSubdirectory(): void
    {
        $tool = new EditTool(basePath: $this->tempDir, createIfNotExists: true);
        $json = $tool->__invoke('sub/dir/file.txt', '', 'Nested content');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertFileExists($this->tempDir . '/sub/dir/file.txt');
    }

    /**
     * Проверяет отказ при попытке создать файл, когда createIfNotExists=false.
     *
     * @return void
     */
    public function testCreateNewFileDisabledReturnsError(): void
    {
        $tool = new EditTool(basePath: $this->tempDir, createIfNotExists: false);
        $json = $tool->__invoke('nope.txt', '', 'content');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('не существует', $data['message']);
    }

    /**
     * Проверяет ошибку при непустом old_string для несуществующего файла.
     *
     * Создание нового файла требует пустой old_string.
     *
     * @return void
     */
    public function testCreateNewFileWithNonEmptyOldStringReturnsError(): void
    {
        $tool = new EditTool(basePath: $this->tempDir, createIfNotExists: true);
        $json = $tool->__invoke('nope.txt', 'something', 'content');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('old_string должен быть пустым', $data['message']);
    }

    /**
     * Проверяет создание резервной копии (.bak) при createBackup=true.
     *
     * Копия должна содержать оригинальное содержимое файла.
     *
     * @return void
     */
    public function testCreatesBackup(): void
    {
        file_put_contents($this->tempDir . '/backup_test.txt', 'Original Content');

        $tool = new EditTool(basePath: $this->tempDir, createBackup: true);
        $json = $tool->__invoke('backup_test.txt', 'Original', 'Modified');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertFileExists($this->tempDir . '/backup_test.txt.bak');
        $this->assertSame('Original Content', file_get_contents($this->tempDir . '/backup_test.txt.bak'));
    }

    /**
     * Проверяет защиту от path-traversal (../../etc/passwd).
     *
     * Попытка выйти за пределы basePath должна быть отклонена.
     *
     * @return void
     */
    public function testPathTraversalReturnsError(): void
    {
        $tool = new EditTool(basePath: $this->tempDir);
        $json = $tool->__invoke('../../etc/passwd', 'root', 'hacked');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('запрещён', $data['message']);
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
        file_put_contents($this->tempDir . '/big.txt', str_repeat('x', 200));

        $tool = new EditTool(basePath: $this->tempDir, maxFileSize: 100, createBackup: false);
        $json = $tool->__invoke('big.txt', 'xxx', 'yyy');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('большой', $data['message']);
    }

    /**
     * Проверяет, что пустой old_string для существующего файла вызывает ошибку.
     *
     * Пустой old_string допускается только при создании нового файла.
     *
     * @return void
     */
    public function testEmptyOldStringForExistingFileReturnsError(): void
    {
        file_put_contents($this->tempDir . '/existing.txt', 'content');

        $tool = new EditTool(basePath: $this->tempDir, createBackup: false);
        $json = $tool->__invoke('existing.txt', '', 'new content');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('не может быть пустым', $data['message']);
    }

    /**
     * Проверяет, что сеттеры корректно обновляют свойства инструмента.
     *
     * @return void
     */
    public function testSettersUpdateProperties(): void
    {
        $tool = new EditTool();
        $tool->setBasePath($this->tempDir)
             ->setCreateBackup(false)
             ->setCreateIfNotExists(true)
             ->setMaxFileSize(2048);

        $json = $tool->__invoke('setter_test.txt', '', 'test');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
    }

    /**
     * Проверяет, что замена фрагмента сохраняет остальное содержимое файла.
     *
     * Заменяется метод bar() в PHP-классе, при этом объявление класса
     * и остальной код остаются нетронутыми.
     *
     * @return void
     */
    public function testReplacementPreservesRestOfFile(): void
    {
        $original = "<?php\nclass Foo {\n    public function bar() {}\n}\n";
        file_put_contents($this->tempDir . '/code.php', $original);

        $tool = new EditTool(basePath: $this->tempDir, createBackup: false);
        $json = $tool->__invoke(
            'code.php',
            "    public function bar() {}",
            "    public function bar(): string\n    {\n        return 'hello';\n    }",
        );
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $newContent = file_get_contents($this->tempDir . '/code.php');
        $this->assertStringContainsString("class Foo {", $newContent);
        $this->assertStringContainsString("return 'hello'", $newContent);
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
