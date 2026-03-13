<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\SearchReplaceResultDto;
use app\modules\neuron\helpers\FileSystemHelper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_key_exists;
use function array_map;
use function json_decode;
use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Высокоуровневый инструмент поиска и замены текста в файлах.
 *
 * Использует GrepTool для поиска вхождений и, при отключённом dry-run,
 * EditTool для безопасной точечной замены. Результат — структурированный
 * отчёт о количестве замен по файлам и возможных ошибках.
 */
class SearchReplaceTool extends ATool
{
    /**
     * Базовая директория, в пределах которой выполняется поиск и замена.
     */
    protected string $basePath;

    /**
     * Максимальное количество замен (для защиты от слишком широких операций).
     */
    protected int $maxReplacements = 500;

    /**
     * @param string $basePath    Базовая директория
     * @param int    $maxReplacements Лимит общего числа замен
     * @param string $name        Имя инструмента
     * @param string $description Описание инструмента
     */
    public function __construct(
        string $basePath = '',
        int $maxReplacements = 500,
        string $name = 'search_replace',
        string $description = 'Поиск и замена текста в файлах с поддержкой dry-run и отчётом по изменениям.',
    ) {
        parent::__construct(name: $name, description: $description);
        $this->basePath = $basePath !== '' ? $basePath : (string) getcwd();
        $this->maxReplacements = $maxReplacements;
    }

    /**
     * Описание входных параметров инструмента для LLM.
     *
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'pattern',
                type: PropertyType::STRING,
                description: 'Строка или регулярное выражение для поиска (как в GrepTool).',
                required: true,
            ),
            ToolProperty::make(
                name: 'replacement',
                type: PropertyType::STRING,
                description: 'Строка, на которую нужно заменить найденный текст.',
                required: true,
            ),
            ToolProperty::make(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Относительный путь к файлу или директории (по умолчанию — вся база).',
                required: false,
            ),
            ToolProperty::make(
                name: 'include',
                type: PropertyType::STRING,
                description: 'Glob-шаблон для фильтрации файлов (например, \"*.php\").',
                required: false,
            ),
            ToolProperty::make(
                name: 'dry_run',
                type: PropertyType::BOOLEAN,
                description: 'Если true — только план изменений без фактической правки файлов.',
                required: false,
            ),
        ];
    }

    /**
     * Выполняет поиск и (опционально) замену текста в файлах.
     *
     * @param string      $pattern     Паттерн для поиска
     * @param string      $replacement Строка-замена
     * @param string|null $path       Путь к файлу или директории
     * @param string|null $include    Glob-шаблон фильтрации файлов
     * @param bool|null   $dry_run    Режим dry-run (по умолчанию true)
     *
     * @return string JSON-строка с результатом {@see SearchReplaceResultDto::toArray()}
     */
    public function __invoke(
        string $pattern,
        string $replacement,
        ?string $path = null,
        ?string $include = null,
        ?bool $dry_run = null
    ): string {
        $dryRun = $dry_run ?? true;

        $grep = new GrepTool(
            basePath: $this->basePath,
            maxMatches: $this->maxReplacements,
        );

        $relativePath = $path ?? '';
        $searchPath = $relativePath !== ''
            ? FileSystemHelper::resolvePath($this->basePath, $relativePath)
            : $this->basePath;

        if (!FileSystemHelper::isPathSafe($searchPath, $this->basePath)) {
            return json_encode(
                [
                    'error' => 'Путь выходит за пределы базовой директории.',
                ],
                JSON_UNESCAPED_UNICODE
            );
        }

        $grepJson = $grep->__invoke($pattern, $relativePath !== '' ? $relativePath : null, $include);

        try {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode($grepJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return json_encode(
                [
                    'error' => 'Не удалось разобрать результат GrepTool: ' . $e->getMessage(),
                ],
                JSON_UNESCAPED_UNICODE
            );
        }

        if (array_key_exists('error', $decoded)) {
            return json_encode(
                [
                    'error' => 'Ошибка поиска: ' . (string) $decoded['error'],
                ],
                JSON_UNESCAPED_UNICODE
            );
        }

        /** @var array<int,array<string,mixed>> $matches */
        $matches = $decoded['matches'] ?? [];

        $changes = [];
        $errors = [];
        $totalChanges = 0;

        if ($matches === []) {
            $resultDto = new SearchReplaceResultDto(
                $pattern,
                $replacement,
                $dryRun,
                $changes,
                0,
                $errors
            );

            return json_encode($resultDto->toArray(), JSON_UNESCAPED_UNICODE);
        }

        $groupedByFile = [];
        foreach ($matches as $match) {
            $file = (string) ($match['filePath'] ?? '');
            if ($file === '') {
                continue;
            }
            $groupedByFile[$file] = true;
        }

        if (!$dryRun) {
            $editTool = new EditTool(
                basePath: $this->basePath,
                createBackup: true,
                createIfNotExists: false
            );

            foreach (array_keys($groupedByFile) as $relativeFile) {
                $editJson = $editTool->__invoke($relativeFile, $pattern, $replacement);
                try {
                    /** @var array<string,mixed> $editDecoded */
                    $editDecoded = json_decode($editJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    $errors[] = 'Ошибка правки файла ' . $relativeFile . ': ' . $e->getMessage();
                    continue;
                }

                if (!($editDecoded['success'] ?? false)) {
                    $errors[] = 'Не удалось отредактировать ' . $relativeFile . ': ' . (string) ($editDecoded['message'] ?? '');
                    continue;
                }

                $replCount = (int) ($editDecoded['replacements'] ?? 0);
                if ($replCount > 0) {
                    $changes[$relativeFile] = ($changes[$relativeFile] ?? 0) + $replCount;
                    $totalChanges += $replCount;
                }
            }
        } else {
            foreach (array_keys($groupedByFile) as $relativeFile) {
                $changes[$relativeFile] = $changes[$relativeFile] ?? 1;
                $totalChanges += 1;
            }
        }

        $resultDto = new SearchReplaceResultDto(
            $pattern,
            $replacement,
            $dryRun,
            $changes,
            $totalChanges,
            $errors
        );

        return json_encode($resultDto->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Устанавливает базовую директорию для поиска и замены.
     *
     * @return self
     */
    public function setBasePath(string $basePath): self
    {
        $this->basePath = $basePath;

        return $this;
    }

    /**
     * Устанавливает лимит общего количества замен.
     *
     * @return self
     */
    public function setMaxReplacements(int $maxReplacements): self
    {
        $this->maxReplacements = $maxReplacements;

        return $this;
    }
}

