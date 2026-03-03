<?php

declare(strict_types=1);

namespace Tests\Dir;

use app\modules\neuron\classes\dir\DirPriority;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see DirPriority}.
 *
 * DirPriority — приоритетный список директорий для поиска файлов.
 * Поиск ведётся в порядке приоритета (первый элемент — высший приоритет):
 * для каждого запроса перебираются все директории, и возвращается первый
 * найденный файл/каталог. Используется повсюду в проекте для разрешения
 * путей к конфигам, навыкам, спискам заданий и т.д.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\dir\DirPriority}
 */
class DirPriorityTest extends TestCase
{
    /** @var string Корневая временная директория для текущего теста. */
    private string $tmpBase;

    /** @var string Первая (приоритетная) директория. */
    private string $dirA;

    /** @var string Вторая (менее приоритетная) директория. */
    private string $dirB;

    /**
     * Перед каждым тестом создаём две временные директории.
     */
    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/neuronapp_test_' . uniqid();
        $this->dirA = $this->tmpBase . '/a';
        $this->dirB = $this->tmpBase . '/b';

        mkdir($this->dirA, 0777, true);
        mkdir($this->dirB, 0777, true);
    }

    /**
     * После каждого теста полностью удаляем временные директории.
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->tmpBase);
    }

    /**
     * Рекурсивное удаление директории.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ══════════════════════════════════════════════════════════════
    //  Конструктор
    // ══════════════════════════════════════════════════════════════

    /**
     * Конструктор с массивом реально существующих директорий — объект создаётся
     * без ошибок.
     */
    public function testConstructorWithValidDirectories(): void
    {
        $dp = new DirPriority([$this->dirA, $this->dirB]);
        $this->assertInstanceOf(DirPriority::class, $dp);
    }

    /**
     * Пустой массив директорий — допустим (поиск всегда будет возвращать null).
     */
    public function testConstructorWithEmptyArray(): void
    {
        $dp = new DirPriority([]);
        $this->assertInstanceOf(DirPriority::class, $dp);
    }

    /**
     * Несуществующая директория в списке — выбрасывается InvalidArgumentException.
     */
    public function testConstructorThrowsOnNonExistentDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DirPriority([$this->dirA, '/nonexistent/path/xyz']);
    }

    // ══════════════════════════════════════════════════════════════
    //  resolveFile — поиск файла без расширений
    // ══════════════════════════════════════════════════════════════

    /**
     * Файл найден по точному имени (без перебора расширений).
     */
    public function testResolveFileWithoutExtensions(): void
    {
        file_put_contents($this->dirA . '/test.txt', 'content');
        $dp = new DirPriority([$this->dirA]);

        $this->assertSame($this->dirA . '/test.txt', $dp->resolveFile('test.txt'));
    }

    /**
     * Файл не найден ни в одной директории — возвращается null.
     */
    public function testResolveFileReturnsNullWhenNotFound(): void
    {
        $dp = new DirPriority([$this->dirA]);
        $this->assertNull($dp->resolveFile('missing.txt'));
    }

    /**
     * Файл существует в обеих директориях — возвращается путь из первой
     * (приоритетной) директории.
     */
    public function testResolveFilePriorityOrder(): void
    {
        file_put_contents($this->dirA . '/test.txt', 'from A');
        file_put_contents($this->dirB . '/test.txt', 'from B');
        $dp = new DirPriority([$this->dirA, $this->dirB]);

        $this->assertSame($this->dirA . '/test.txt', $dp->resolveFile('test.txt'));
    }

    /**
     * Файл отсутствует в первой директории, но есть во второй —
     * возвращается из второй (fallback).
     */
    public function testResolveFileFallsBackToSecondDir(): void
    {
        file_put_contents($this->dirB . '/test.txt', 'from B');
        $dp = new DirPriority([$this->dirA, $this->dirB]);

        $this->assertSame($this->dirB . '/test.txt', $dp->resolveFile('test.txt'));
    }

    // ══════════════════════════════════════════════════════════════
    //  resolveFile — поиск файла с перебором расширений
    // ══════════════════════════════════════════════════════════════

    /**
     * При наличии обоих файлов config.php и config.jsonc —
     * возвращается первый по приоритету расширений (php).
     */
    public function testResolveFileWithExtensionsFindsFirst(): void
    {
        file_put_contents($this->dirA . '/config.php', '<?php return [];');
        file_put_contents($this->dirA . '/config.jsonc', '{}');
        $dp = new DirPriority([$this->dirA]);

        $result = $dp->resolveFile('config', ['php', 'jsonc']);
        $this->assertSame($this->dirA . '/config.php', $result);
    }

    /**
     * Файл с первым расширением отсутствует — используется файл
     * со вторым расширением.
     */
    public function testResolveFileWithExtensionsFallsToSecond(): void
    {
        file_put_contents($this->dirA . '/config.jsonc', '{}');
        $dp = new DirPriority([$this->dirA]);

        $result = $dp->resolveFile('config', ['php', 'jsonc']);
        $this->assertSame($this->dirA . '/config.jsonc', $result);
    }

    /**
     * Если имя файла уже содержит расширение из списка, оно сначала
     * отбрасывается, а затем подставляется заново. Результат — корректный путь.
     */
    public function testResolveFileStripsKnownExtension(): void
    {
        file_put_contents($this->dirA . '/config.php', '<?php');
        $dp = new DirPriority([$this->dirA]);

        $result = $dp->resolveFile('config.php', ['php', 'jsonc']);
        $this->assertSame($this->dirA . '/config.php', $result);
    }

    /**
     * Ни одного файла с перечисленными расширениями не найдено — null.
     */
    public function testResolveFileWithExtensionsReturnsNull(): void
    {
        $dp = new DirPriority([$this->dirA]);
        $this->assertNull($dp->resolveFile('missing', ['php', 'jsonc']));
    }

    /**
     * Поиск файла в подкаталоге (sub/file) с расширениями.
     */
    public function testResolveFileWithSubdirectory(): void
    {
        mkdir($this->dirA . '/sub', 0777, true);
        file_put_contents($this->dirA . '/sub/file.txt', 'ok');
        $dp = new DirPriority([$this->dirA]);

        $result = $dp->resolveFile('sub/file', ['txt']);
        $this->assertSame($this->dirA . '/sub/file.txt', $result);
    }

    /**
     * Файл отсутствует в первой директории, но есть во второй —
     * при переборе расширений тоже работает fallback.
     */
    public function testResolveFilePriorityAcrossDirsWithExtensions(): void
    {
        file_put_contents($this->dirB . '/agent.php', '<?php');
        $dp = new DirPriority([$this->dirA, $this->dirB]);

        $result = $dp->resolveFile('agent', ['php', 'jsonc']);
        $this->assertSame($this->dirB . '/agent.php', $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  resolveDir — поиск директории
    // ══════════════════════════════════════════════════════════════

    /**
     * Существующая поддиректория найдена — возвращается полный путь.
     */
    public function testResolveDirFindsSubdir(): void
    {
        mkdir($this->dirA . '/agents', 0777, true);
        $dp = new DirPriority([$this->dirA]);

        $this->assertSame($this->dirA . '/agents', $dp->resolveDir('agents'));
    }

    /**
     * Несуществующая поддиректория — null.
     */
    public function testResolveDirReturnsNullForMissing(): void
    {
        $dp = new DirPriority([$this->dirA]);
        $this->assertNull($dp->resolveDir('nonexistent'));
    }

    /**
     * Директория есть в обеих базовых — возвращается из приоритетной.
     */
    public function testResolveDirPriority(): void
    {
        mkdir($this->dirA . '/shared', 0777, true);
        mkdir($this->dirB . '/shared', 0777, true);
        $dp = new DirPriority([$this->dirA, $this->dirB]);

        $this->assertSame($this->dirA . '/shared', $dp->resolveDir('shared'));
    }

    /**
     * Директория есть только во второй базовой — fallback.
     */
    public function testResolveDirFallback(): void
    {
        mkdir($this->dirB . '/only_b', 0777, true);
        $dp = new DirPriority([$this->dirA, $this->dirB]);

        $this->assertSame($this->dirB . '/only_b', $dp->resolveDir('only_b'));
    }

    /**
     * Пустой относительный путь — возвращается сама базовая директория.
     */
    public function testResolveDirEmptyRelPath(): void
    {
        $dp = new DirPriority([$this->dirA]);
        $this->assertSame($this->dirA, $dp->resolveDir(''));
    }

    /**
     * Ведущие/хвостовые разделители пути корректно обрезаются.
     */
    public function testResolveDirTrimsSlashes(): void
    {
        mkdir($this->dirA . '/sub', 0777, true);
        $dp = new DirPriority([$this->dirA]);

        $this->assertSame($this->dirA . '/sub', $dp->resolveDir('/sub/'));
    }

    /**
     * Расширение с ведущей точкой (.jsonc вместо jsonc) обрабатывается корректно.
     */
    public function testResolveFileWithExtensionDotPrefix(): void
    {
        file_put_contents($this->dirA . '/config.jsonc', '{}');
        $dp = new DirPriority([$this->dirA]);

        $result = $dp->resolveFile('config', ['.jsonc']);
        $this->assertSame($this->dirA . '/config.jsonc', $result);
    }

    /**
     * Хвостовой слэш в пути директории корректно обрезается конструктором.
     */
    public function testConstructorTrimsTrailingSlash(): void
    {
        $dp = new DirPriority([$this->dirA . '/']);
        file_put_contents($this->dirA . '/test.txt', 'ok');
        $this->assertNotNull($dp->resolveFile('test.txt'));
    }
}
