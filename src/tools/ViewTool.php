<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\ViewResultDto;
use app\modules\neuron\helpers\FileSystemHelper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function array_slice;
use function count;
use function explode;
use function file_get_contents;
use function filesize;
use function getcwd;
use function implode;
use function is_file;
use function is_readable;
use function json_encode;
use function min;
use function sprintf;
use function str_pad;
use function strlen;

use const JSON_UNESCAPED_UNICODE;
use const STR_PAD_LEFT;

/**
 * Инструмент чтения содержимого файла с нумерацией строк.
 *
 * Предоставляет LLM содержимое одного файла в формате `{номер}|{строка}`,
 * что позволяет модели точно ссылаться на конкретные строки при последующем
 * вызове {@see EditTool}. Часто используется после {@see GlobTool} или
 * {@see GrepTool} для детального изучения найденных файлов.
 *
 * Безопасность:
 * - Путь проверяется через {@see FileSystemHelper::isPathSafe()} для защиты
 *   от path-traversal атак (попыток выйти за пределы basePath через «..»).
 * - Бинарные файлы автоматически отклоняются с информативным сообщением.
 * - Размер файла ограничен настройкой maxFileSize (по умолчанию 1 МБ).
 *
 * Поддерживается частичное чтение: параметры start_line и end_line (1-based,
 * включительно) позволяют запросить конкретный диапазон строк. Если не указаны,
 * возвращается весь файл, но не более maxLines строк.
 *
 * Все ограничения и настройки вынесены в свойства класса и могут быть изменены
 * через конструктор или сеттеры: basePath, maxFileSize, maxLines, encoding.
 *
 * Результат возвращается в виде JSON через {@see ViewResultDto}.
 *
 * @see ViewResultDto  Структура результата чтения
 * @see FileSystemHelper Проверка пути и типа файла
 */
class ViewTool extends Tool
{
    /** @var string Базовая директория для разрешения путей */
    protected string $basePath;

    /** @var int Максимальный размер файла в байтах */
    protected int $maxFileSize = 1048576;

    /** @var int Максимальное количество возвращаемых строк */
    protected int $maxLines = 2000;

    /** @var string Кодировка файла */
    protected string $encoding = 'UTF-8';

    /**
     * @param string $basePath    Базовая директория
     * @param int    $maxFileSize Максимальный размер файла (байт)
     * @param int    $maxLines    Максимальное количество строк
     * @param string $encoding    Кодировка файлов
     * @param string $name        Имя инструмента
     * @param string $description Описание инструмента
     */
    public function __construct(
        string $basePath = '',
        int $maxFileSize = 1048576,
        int $maxLines = 2000,
        string $encoding = 'UTF-8',
        string $name = 'view',
        string $description = 'Чтение содержимого файла с нумерацией строк. Поддерживает выбор диапазона строк.',
    ) {
        parent::__construct(name: $name, description: $description);

        $this->basePath = $basePath !== '' ? $basePath : (string) getcwd();
        $this->maxFileSize = $maxFileSize;
        $this->maxLines = $maxLines;
        $this->encoding = $encoding;
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
                name: 'path',
                type: PropertyType::STRING,
                description: 'Путь к файлу (абсолютный или относительный к базовой директории).',
                required: true,
            ),
            ToolProperty::make(
                name: 'start_line',
                type: PropertyType::INTEGER,
                description: 'Номер начальной строки (1-based, включительно). Если не указан — с начала файла.',
                required: false,
            ),
            ToolProperty::make(
                name: 'end_line',
                type: PropertyType::INTEGER,
                description: 'Номер конечной строки (1-based, включительно). Если не указан — до конца файла.',
                required: false,
            ),
        ];
    }

    /**
     * Читает содержимое файла и возвращает его с нумерацией строк.
     *
     * Поддерживает частичное чтение через start_line/end_line,
     * ограничивает объём возвращаемых данных через maxLines и maxFileSize.
     *
     * @param string   $path       Путь к файлу
     * @param int|null $start_line Начальная строка (1-based)
     * @param int|null $end_line   Конечная строка (1-based)
     *
     * @return string JSON-строка с результатом чтения
     */
    public function __invoke(string $path, ?int $start_line = null, ?int $end_line = null): string
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
                'error' => "Файл '{$path}' является бинарным и не может быть отображён.",
            ], JSON_UNESCAPED_UNICODE);
        }

        $content = file_get_contents($resolvedPath);
        if ($content === false) {
            return json_encode([
                'error' => "Не удалось прочитать файл '{$path}'.",
            ], JSON_UNESCAPED_UNICODE);
        }

        $allLines = explode("\n", $content);
        $totalLines = count($allLines);

        $effectiveStart = $start_line !== null ? max(1, $start_line) : 1;
        $effectiveEnd = $end_line !== null ? min($end_line, $totalLines) : $totalLines;

        if ($effectiveStart > $totalLines) {
            return json_encode([
                'error' => sprintf(
                    "Начальная строка %d превышает общее количество строк в файле (%d).",
                    $effectiveStart,
                    $totalLines,
                ),
            ], JSON_UNESCAPED_UNICODE);
        }

        $selectedLines = array_slice($allLines, $effectiveStart - 1, $effectiveEnd - $effectiveStart + 1);
        $truncated = count($selectedLines) > $this->maxLines;

        if ($truncated) {
            $selectedLines = array_slice($selectedLines, 0, $this->maxLines);
            $effectiveEnd = $effectiveStart + $this->maxLines - 1;
        }

        $padWidth = strlen((string) $effectiveEnd);
        $numberedLines = [];
        foreach ($selectedLines as $index => $line) {
            $lineNum = $effectiveStart + $index;
            $numberedLines[] = str_pad((string) $lineNum, $padWidth, ' ', STR_PAD_LEFT) . '|' . $line;
        }

        $dto = new ViewResultDto(
            filePath: $path,
            content: implode("\n", $numberedLines),
            startLine: $effectiveStart,
            endLine: $effectiveStart + count($selectedLines) - 1,
            totalLines: $totalLines,
            truncated: $truncated,
        );

        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Устанавливает базовую директорию для разрешения относительных путей.
     *
     * Также используется для проверки path-traversal: запрашиваемый файл
     * должен находиться внутри этой директории.
     *
     * @param string $basePath Абсолютный путь к корневой директории
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setBasePath(string $basePath): self
    {
        $this->basePath = $basePath;
        return $this;
    }

    /**
     * Устанавливает максимальный размер файла, допустимый для чтения.
     *
     * Файлы, превышающие этот размер, будут отклонены с информативным сообщением.
     * Предотвращает загрузку очень больших файлов в память.
     *
     * @param int $maxFileSize Максимальный размер файла в байтах (по умолчанию 1 МБ)
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setMaxFileSize(int $maxFileSize): self
    {
        $this->maxFileSize = $maxFileSize;
        return $this;
    }

    /**
     * Устанавливает максимальное количество возвращаемых строк.
     *
     * Если запрашиваемый диапазон содержит больше строк, вывод будет усечён,
     * а поле truncated в результате — true.
     *
     * @param int $maxLines Максимальное число строк (по умолчанию 2000)
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setMaxLines(int $maxLines): self
    {
        $this->maxLines = $maxLines;
        return $this;
    }

    /**
     * Устанавливает ожидаемую кодировку файлов.
     *
     * В текущей реализации зарезервировано для будущего использования.
     * Файлы всегда читаются как есть (предполагается UTF-8).
     *
     * @param string $encoding Название кодировки (по умолчанию 'UTF-8')
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setEncoding(string $encoding): self
    {
        $this->encoding = $encoding;
        return $this;
    }
}
