<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\helpers\FileSystemHelper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function filesize;
use function getcwd;
use function is_file;
use function is_readable;
use function json_encode;
use function sprintf;

use const JSON_UNESCAPED_UNICODE;

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
            return json_encode([
                'error' => "Доступ запрещён: путь выходит за пределы базовой директории.",
            ], JSON_UNESCAPED_UNICODE);
        }

        if (!is_file($resolvedPath)) {
            return json_encode([
                'error' => "Файл '{$path}' не существует.",
            ], JSON_UNESCAPED_UNICODE);
        }

        if (!is_readable($resolvedPath)) {
            return json_encode([
                'error' => "Файл '{$path}' недоступен для чтения.",
            ], JSON_UNESCAPED_UNICODE);
        }

        $size = filesize($resolvedPath);
        if ($size === false || $size > $this->maxFileSize) {
            return json_encode([
                'error' => sprintf(
                    "Файл '%s' слишком большой (%d байт). Максимум: %d байт.",
                    $path,
                    $size ?: 0,
                    $this->maxFileSize,
                ),
            ], JSON_UNESCAPED_UNICODE);
        }

        if (!FileSystemHelper::isTextFile($resolvedPath)) {
            return json_encode([
                'error' => "Файл '{$path}' является бинарным и не может быть обработан.",
            ], JSON_UNESCAPED_UNICODE);
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
