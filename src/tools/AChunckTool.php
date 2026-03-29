<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\helpers\FileSystemHelper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function filesize;
use function getcwd;
use function is_file;
use function is_readable;
use function sprintf;

/**
 * Абстрактный базовый класс для инструментов, работающих с текстовыми файлами чанками.
 *
 * Инкапсулирует общие параметры (basePath, maxFileSize) и логику проверки пути,
 * прав доступа, типа файла и размера. Наследники самостоятельно читают файл
 * построчно, не загружая его целиком в память.
 *
 * Все инструменты наследники должны использовать {@see validateTextFile()} для
 * валидации пути и {@see makePathProperty()} для описания параметра path.
 */
abstract class AChunckTool extends ATool
{
    /** @var string Базовая директория для разрешения путей */
    protected string $basePath;

    /** @var int Максимальный размер файла в байтах */
    protected int $maxFileSize = 1048576;

    /**
     * @param string $basePath    Базовая директория
     * @param int    $maxFileSize Максимальный размер файла (байт)
     * @param string $name        Имя инструмента
     * @param string $description Описание инструмента
     */
    public function __construct(
        string $basePath = '',
        int $maxFileSize = 1048576,
        string $name = '',
        string $description = '',
    ) {
        parent::__construct(name: $name, description: $description);

        $this->basePath = $basePath !== '' ? $basePath : (string) getcwd();
        $this->maxFileSize = $maxFileSize;
    }

    /**
     * Стандартное описание параметра path для инструментов чанков.
     *
     * @return ToolProperty
     */
    protected function makePathProperty(): ToolProperty
    {
        return ToolProperty::make(
            name: 'path',
            type: PropertyType::STRING,
            description: 'Путь к файлу (абсолютный или относительный к базовой директории).',
            required: true,
        );
    }

    /**
     * Проводит общую валидацию текстового файла (без чтения содержимого).
     *
     * При ошибках возвращает JSON-строку с описанием ошибки.
     *
     * @param string $path Путь к файлу (как запрошен инструментом)
     *
     * @return array{path: string, resolvedPath: string, size: int}|string
     */
    protected function validateTextFile(string $path): array|string
    {
        $resolvedPath = FileSystemHelper::resolvePath($this->basePath, $path);

        if (!FileSystemHelper::isPathSafe($resolvedPath, $this->basePath)) {
            return JsonHelper::encodeThrow([
                'error' => "Доступ запрещён: путь выходит за пределы базовой директории.",
            ]);
        }

        if (!is_file($resolvedPath)) {
            return JsonHelper::encodeThrow([
                'error' => "Файл '{$path}' не существует.",
            ]);
        }

        if (!is_readable($resolvedPath)) {
            return JsonHelper::encodeThrow([
                'error' => "Файл '{$path}' недоступен для чтения.",
            ]);
        }

        $size = filesize($resolvedPath);
        if ($size === false || $size > $this->maxFileSize) {
            return JsonHelper::encodeThrow([
                'error' => sprintf(
                    "Файл '%s' слишком большой (%d байт). Максимум: %d байт.",
                    $path,
                    $size ?: 0,
                    $this->maxFileSize,
                ),
            ]);
        }

        if (!FileSystemHelper::isTextFile($resolvedPath)) {
            return JsonHelper::encodeThrow([
                'error' => "Файл '{$path}' является бинарным и не может быть обработан.",
            ]);
        }

        return [
            'path' => $path,
            'resolvedPath' => $resolvedPath,
            'size' => $size ?: 0,
        ];
    }

    /**
     * Устанавливает базовую директорию для разрешения относительных путей.
     *
     * @param string $basePath Абсолютный путь к корневой директории
     *
     * @return static
     */
    public function setBasePath(string $basePath): static
    {
        $this->basePath = $basePath;
        return $this;
    }

    /**
     * Устанавливает максимальный размер файла, допустимый для чтения.
     *
     * @param int $maxFileSize Максимальный размер файла в байтах
     *
     * @return static
     */
    public function setMaxFileSize(int $maxFileSize): static
    {
        $this->maxFileSize = $maxFileSize;
        return $this;
    }
}
