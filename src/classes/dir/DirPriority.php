<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dir;

use InvalidArgumentException;

/**
 * Приоритетный список директорий для поиска файлов.
 *
 * Позволяет искать файл по относительному пути в нескольких директориях;
 * возвращается путь к первому найденному файлу (по порядку директорий).
 */
class DirPriority
{
    /**
     * Абсолютные пути к директориям (в порядке приоритета).
     *
     * @var list<string>
     */
    private array $directories;

    /**
     * @param list<string> $directories Массив абсолютных путей к директориям.
     *
     * @throws InvalidArgumentException Если одна из директорий не существует.
     */
    public function __construct(array $directories)
    {
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                throw new InvalidArgumentException(sprintf('Directory does not exist: %s', $dir));
            }
        }

        $this->directories = array_map(
            static fn(string $d): string => rtrim($d, DIRECTORY_SEPARATOR),
            array_values($directories)
        );
    }

    /**
     * Ищет файл в директориях и возвращает абсолютный путь к первому найденному.
     *
     * Директории перебираются в порядке приоритета. Если передан массив расширений,
     * то для каждой директории проверяются варианты имени с каждым расширением
     * (в порядке указания); возвращается первый существующий файл.
     *
     * @param string       $relFileName Относительный путь к файлу (например, "config.jsonc"
     *                                  или "agents/neuron1"). При указании $extensions
     *                                  точка и расширение в имени отбрасываются.
     * @param list<string>|null $extensions Список расширений (без точки), например ['php', 'jsonc'].
     *                                  Если null, используется имя файла как есть.
     *
     * @return string|null Абсолютный путь к файлу или null, если файл не найден.
     */
    public function resolveFile(string $relFileName, ?array $extensions = null): ?string
    {
        if ($extensions !== null) {
            $base = $this->stripExtension($relFileName, $extensions);

            foreach ($this->directories as $dir) {
                foreach ($extensions as $ext) {
                    $ext = ltrim($ext, '.');
                    $path = $dir . DIRECTORY_SEPARATOR . $base . '.' . $ext;
                    if (is_file($path)) {
                        return $path;
                    }
                }
            }

            return null;
        }

        foreach ($this->directories as $dir) {
            $path = $dir . DIRECTORY_SEPARATOR . $relFileName;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Убирает расширение из имени файла, если оно входит в список.
     */
    private function stripExtension(string $relFileName, array $extensions): string
    {
        $base = $relFileName;
        foreach ($extensions as $ext) {
            $ext = ltrim($ext, '.');
            $suffix = '.' . $ext;
            if (str_ends_with($base, $suffix)) {
                return substr($base, 0, -strlen($suffix));
            }
        }

        return $base;
    }
}
