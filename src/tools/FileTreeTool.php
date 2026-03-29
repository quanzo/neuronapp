<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\classes\dto\tools\FileTreeResultDto;
use app\modules\neuron\helpers\FileSystemHelper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_slice;
use function array_values;
use function count;
use function getcwd;
use function is_dir;
use function scandir;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Инструмент построения дерева файлов/директорий.
 *
 * Рекурсивно обходит файловую систему, начиная с basePath (или поддиректории),
 * и возвращает плоский список узлов (файлы и директории) с глубиной вложенности.
 */
class FileTreeTool extends ATool
{
    /**
     * Базовая директория для обхода.
     */
    protected string $basePath;

    /**
     * Максимальное количество узлов в результате.
     */
    protected int $maxNodes = 2000;

    /**
     * Шаблоны для исключения директорий/файлов.
     *
     * @var string[]
     */
    protected array $excludePatterns = ['.git', 'node_modules', 'vendor', 'temp'];

    /**
     * @param string   $basePath    Базовая директория
     * @param int      $maxNodes    Максимальное число узлов
     * @param string[] $excludePatterns Шаблоны исключения
     * @param string   $name        Имя инструмента
     * @param string   $description Описание инструмента
     */
    public function __construct(
        string $basePath = '',
        int $maxNodes = 2000,
        array $excludePatterns = ['.git', 'node_modules', 'vendor', 'temp'],
        string $name = 'file_tree',
        string $description = 'Строит дерево файлов и директорий, возвращая плоский список узлов с глубиной.',
    ) {
        parent::__construct(name: $name, description: $description);

        $this->basePath = $basePath !== '' ? $basePath : (string) getcwd();
        $this->maxNodes = $maxNodes;
        $this->excludePatterns = $excludePatterns;
    }

    /**
     * Описание входных параметров инструмента.
     *
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Относительный путь от basePath, с которого начинать обход (по умолчанию — корень).',
                required: false,
            ),
            ToolProperty::make(
                name: 'max_depth',
                type: PropertyType::INTEGER,
                description: 'Максимальная глубина обхода (0 — без ограничения).',
                required: false,
            ),
        ];
    }

    /**
     * Строит дерево файлов и возвращает результат в виде JSON.
     *
     * @param string|null $path      Относительный путь от basePath
     * @param int|null    $max_depth Максимальная глубина
     *
     * @return string JSON-строка с результатом {@see FileTreeResultDto::toArray()}
     */
    public function __invoke(?string $path = null, ?int $max_depth = null): string
    {
        $startDir = $path !== null && $path !== ''
            ? FileSystemHelper::resolvePath($this->basePath, $path)
            : $this->basePath;

        if (!FileSystemHelper::isPathSafe($startDir, $this->basePath)) {
            return JsonHelper::encodeThrow(
                [
                    'error' => 'Путь выходит за пределы базовой директории.',
                ]
            );
        }

        if (!is_dir($startDir)) {
            return JsonHelper::encodeThrow(
                [
                    'error' => 'Указанный путь не является директорией.',
                ]
            );
        }

        $nodes = [];
        $maxDepth = $max_depth ?? 0;
        $this->walk($startDir, $this->basePath, 0, $maxDepth, $nodes);

        $totalNodes = count($nodes);
        $truncated = false;
        if ($totalNodes > $this->maxNodes) {
            $nodes = array_slice($nodes, 0, $this->maxNodes);
            $truncated = true;
        }

        $dto = new FileTreeResultDto(
            basePath: $this->basePath,
            nodes: array_values($nodes),
            truncated: $truncated,
            totalNodes: $totalNodes
        );

        return JsonHelper::encodeThrow($dto->toArray());
    }

    /**
     * Рекурсивный обход директории с учётом глубины и шаблонов исключения.
     *
     * @param string $dir      Текущая директория
     * @param string $base     Базовый путь для вычисления относительного пути
     * @param int    $depth    Текущая глубина
     * @param int    $maxDepth Максимальная глубина (0 — без ограничения)
     * @param array<int,array{path:string,type:string,depth:int}> $nodes Аккумулятор узлов
     */
    private function walk(
        string $dir,
        string $base,
        int $depth,
        int $maxDepth,
        array &$nodes
    ): void {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
            if (FileSystemHelper::shouldExclude($fullPath, $this->excludePatterns)) {
                continue;
            }

            $relativePath = substr($fullPath, strlen($base) + 1);

            if (is_dir($fullPath)) {
                $nodes[] = [
                    'path' => $relativePath,
                    'type' => 'dir',
                    'depth' => $depth,
                ];

                if ($maxDepth === 0 || $depth < $maxDepth) {
                    $this->walk($fullPath, $base, $depth + 1, $maxDepth, $nodes);
                }
            } else {
                $nodes[] = [
                    'path' => $relativePath,
                    'type' => 'file',
                    'depth' => $depth,
                ];
            }

            if (count($nodes) >= $this->maxNodes) {
                return;
            }
        }
    }

    /**
     * Устанавливает базовый путь.
     *
     * @return self
     */
    public function setBasePath(string $basePath): self
    {
        $this->basePath = $basePath;

        return $this;
    }

    /**
     * Устанавливает максимальное количество узлов.
     *
     * @return self
     */
    public function setMaxNodes(int $maxNodes): self
    {
        $this->maxNodes = $maxNodes;

        return $this;
    }

    /**
     * Устанавливает шаблоны исключений.
     *
     * @param string[] $excludePatterns
     *
     * @return self
     */
    public function setExcludePatterns(array $excludePatterns): self
    {
        $this->excludePatterns = $excludePatterns;

        return $this;
    }
}
