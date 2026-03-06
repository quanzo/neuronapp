<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use function array_filter;
use function count;
use function file_get_contents;
use function fnmatch;
use function is_link;
use function realpath;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Вспомогательный класс для безопасной работы с файловой системой.
 *
 * Предоставляет статические методы, используемые инструментами LLM
 * ({@see \app\modules\neuron\tools\GlobTool}, {@see \app\modules\neuron\tools\GrepTool},
 * {@see \app\modules\neuron\tools\ViewTool}, {@see \app\modules\neuron\tools\EditTool})
 * для решения типовых задач:
 *
 * - Разрешение и нормализация путей (resolvePath)
 * - Защита от path-traversal атак (isPathSafe)
 * - Эвристическое определение бинарных файлов (isTextFile)
 * - Фильтрация по шаблонам исключений (shouldExclude)
 * - Проверка символических ссылок (isSymlink)
 *
 * Все методы не зависят от состояния и являются чистыми функциями,
 * что позволяет безопасно вызывать их из любого контекста.
 */
class FileSystemHelper
{
    /**
     * Расширения файлов, считающихся бинарными.
     *
     * Используется в {@see isTextFile()} для быстрой проверки по расширению
     * перед дорогостоящим чтением содержимого файла. Включает изображения,
     * аудио/видео, архивы, исполняемые файлы, офисные документы, шрифты,
     * скомпилированные файлы и базы данных.
     *
     * @var string[]
     */
    private const BINARY_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'bmp', 'ico', 'webp', 'svg',
        'mp3', 'mp4', 'avi', 'mkv', 'wav', 'flac', 'ogg',
        'zip', 'tar', 'gz', 'bz2', 'rar', '7z', 'xz', 'phar',
        'exe', 'dll', 'so', 'dylib', 'bin',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'class', 'o', 'pyc', 'pyo',
        'sqlite', 'db',
    ];

    /**
     * Разрешает и нормализует путь относительно базовой директории.
     *
     * Если передан абсолютный путь, возвращает его с нормализацией.
     * Если передан относительный — объединяет с базовым путём.
     *
     * @param string $basePath     Базовая директория
     * @param string $relativePath Путь (абсолютный или относительный)
     *
     * @return string Нормализованный абсолютный путь
     */
    public static function resolvePath(string $basePath, string $relativePath): string
    {
        if ($relativePath === '') {
            return self::normalizePath($basePath);
        }

        if (str_starts_with($relativePath, DIRECTORY_SEPARATOR)) {
            return self::normalizePath($relativePath);
        }

        return self::normalizePath($basePath . DIRECTORY_SEPARATOR . $relativePath);
    }

    /**
     * Проверяет, что путь не выходит за пределы базовой директории.
     *
     * Использует realpath для разрешения символических ссылок и «..» переходов,
     * предотвращая path-traversal атаки.
     *
     * @param string $path     Проверяемый путь
     * @param string $basePath Базовая директория, за которую нельзя выйти
     *
     * @return bool true, если путь безопасен (находится внутри basePath)
     */
    public static function isPathSafe(string $path, string $basePath): bool
    {
        $realBase = realpath($basePath);
        if ($realBase === false) {
            return false;
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            $checkPath = $path;
            while (true) {
                $parentDir = dirname($checkPath);
                if ($parentDir === $checkPath) {
                    return false;
                }
                $realParent = realpath($parentDir);
                if ($realParent !== false) {
                    return str_starts_with($realParent . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR)
                        || $realParent === $realBase;
                }
                $checkPath = $parentDir;
            }
        }

        return str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)
            || $realPath === $realBase;
    }

    /**
     * Определяет, является ли файл текстовым (не бинарным).
     *
     * Проверяет расширение файла и, при необходимости, первые байты содержимого
     * на наличие нулевых символов (NUL).
     *
     * @param string $path Путь к файлу
     *
     * @return bool true, если файл, вероятно, является текстовым
     */
    public static function isTextFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, self::BINARY_EXTENSIONS, true)) {
            return false;
        }

        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $chunk = file_get_contents($path, false, null, 0, 8192);
        if ($chunk === false || $chunk === '') {
            return true;
        }

        return !str_contains($chunk, "\0");
    }

    /**
     * Проверяет, подпадает ли путь под один из шаблонов исключений.
     *
     * Каждый элемент пути проверяется на соответствие заданным glob-шаблонам,
     * чтобы исключить директории вроде `.git`, `node_modules`, `vendor`.
     *
     * @param string   $path            Проверяемый путь (абсолютный или относительный)
     * @param string[] $excludePatterns Массив glob-шаблонов для исключения
     *
     * @return bool true, если путь должен быть исключён
     */
    public static function shouldExclude(string $path, array $excludePatterns): bool
    {
        if ($excludePatterns === []) {
            return false;
        }

        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), static fn(string $p): bool => $p !== '');

        foreach ($parts as $part) {
            foreach ($excludePatterns as $pattern) {
                if (fnmatch($pattern, $part)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверяет, является ли элемент символической ссылкой.
     *
     * @param string $path Путь к элементу
     *
     * @return bool true, если элемент — символическая ссылка
     */
    public static function isSymlink(string $path): bool
    {
        return is_link($path);
    }

    /**
     * Нормализует путь, удаляя лишние разделители и разрешая «.» и «..».
     *
     * @param string $path Путь для нормализации
     *
     * @return string Нормализованный путь
     */
    private static function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR);
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' && count($normalized) > 0 && end($normalized) !== '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        $result = implode(DIRECTORY_SEPARATOR, $normalized);

        return $isAbsolute ? DIRECTORY_SEPARATOR . $result : $result;
    }
}
