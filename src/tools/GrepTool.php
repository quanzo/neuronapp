<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\classes\dto\tools\GrepMatchDto;
use app\modules\neuron\classes\dto\tools\GrepResultDto;
use app\modules\neuron\helpers\FileSystemHelper;
use app\modules\neuron\tools\ATool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function filesize;
use function fnmatch;
use function getcwd;
use function is_dir;
use function is_file;
use function mb_strlen;
use function mb_substr;
use function preg_last_error_msg;
use function preg_match;
use function scandir;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const PREG_OFFSET_CAPTURE;

/**
 * Инструмент поиска текста или регулярного выражения внутри файлов.
 *
 * Аналог `grep -rn`. Сканирует файлы в указанной директории (или конкретный файл),
 * находит совпадения с заданным паттерном и возвращает результаты с указанием
 * файла, номера строки и содержимого строки. Позволяет LLM быстро находить
 * определения классов, использования функций, переменных и т.д.
 *
 * Паттерн может быть как готовым регулярным выражением (с разделителями, например
 * `/function\s+\w+/`), так и простым текстом — в этом случае он автоматически
 * оборачивается в regex. Бинарные файлы и файлы, превышающие maxFileSize,
 * пропускаются. Результат ограничивается maxMatches совпадениями.
 *
 * Все ограничения и настройки вынесены в свойства класса и могут быть изменены
 * через конструктор или сеттеры: basePath, maxMatches, maxFileSize,
 * excludePatterns, contextLines.
 *
 * Результат возвращается в виде JSON через {@see GrepResultDto}.
 *
 * @see GrepResultDto Структура результата поиска
 * @see GrepMatchDto  Структура одного совпадения
 * @see FileSystemHelper Вспомогательные методы для фильтрации и проверки файлов
 */
class GrepTool extends ATool
{
    /** @var string Базовая директория для поиска */
    protected string $basePath;

    /** @var int Максимальное количество возвращаемых совпадений */
    protected int $maxMatches = 50;

    /** @var int Максимальный размер файла для сканирования (в байтах) */
    protected int $maxFileSize = 1048576;

    /** @var string[] Шаблоны для исключения директорий/файлов */
    protected array $excludePatterns = ['.git', 'node_modules', 'vendor'];

    /** @var int Количество строк контекста вокруг совпадения (не используется в базовом режиме) */
    protected int $contextLines = 0;

    /**
     * @param string   $basePath        Базовая директория для поиска
     * @param int      $maxMatches      Максимальное число совпадений
     * @param int      $maxFileSize     Максимальный размер файла (байт)
     * @param string[] $excludePatterns Шаблоны исключений
     * @param int      $contextLines    Строки контекста вокруг совпадения
     * @param string   $name            Имя инструмента
     * @param string   $description     Описание инструмента
     */
    public function __construct(
        string $basePath = '',
        int $maxMatches = 50,
        int $maxFileSize = 1048576,
        array $excludePatterns = ['.git', 'node_modules', 'vendor'],
        int $contextLines = 0,
        string $name = 'grep',
        string $description = 'Поиск текста или регулярного выражения внутри файлов. Возвращает совпадения с указанием файла и номера строки.',
    ) {
        parent::__construct(name: $name, description: $description);

        $this->basePath        = $basePath !== '' ? $basePath : (string) getcwd();
        $this->maxMatches      = $maxMatches;
        $this->maxFileSize     = $maxFileSize;
        $this->excludePatterns = $excludePatterns;
        $this->contextLines    = $contextLines;
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
                name       : 'pattern',
                type       : PropertyType::STRING,
                description: 'Регулярное выражение или текст для поиска.',
                required   : true,
            ),
            ToolProperty::make(
                name       : 'path',
                type       : PropertyType::STRING,
                description: 'Путь к файлу или директории для поиска (относительно базовой директории). Если не указан — поиск по всему проекту.',
                required   : false,
            ),
            ToolProperty::make(
                name       : 'include',
                type       : PropertyType::STRING,
                description: 'Glob-шаблон для фильтрации файлов (например, "*.php", "*.js").',
                required   : false,
            ),
        ];
    }

    /**
     * Выполняет поиск паттерна в файлах.
     *
     * Оборачивает паттерн в regex-разделители при необходимости,
     * сканирует файлы и собирает совпадения с номерами строк.
     *
     * @param string      $pattern Регулярное выражение или текст
     * @param string|null $path    Путь к файлу или директории
     * @param string|null $include Glob-шаблон для фильтрации файлов
     *
     * @return string JSON-строка с результатом поиска
     */
    public function __invoke(string $pattern, ?string $path = null, ?string $include = null): string
    {
        $regex = $this->buildRegex($pattern);
        if ($regex === null) {
            return JsonHelper::encodeThrow([
                'error' => "Некорректный паттерн: '{$pattern}'. " . preg_last_error_msg(),
            ]);
        }

        $searchPath = $path !== null && $path !== ''
            ? FileSystemHelper::resolvePath($this->basePath, $path)
            : $this->basePath;

        if (!file_exists($searchPath)) {
            return JsonHelper::encodeThrow([
                'error' => "Путь '{$searchPath}' не существует.",
            ]);
        }

        $matches       = [];
        $totalMatches  = 0;
        $filesSearched = 0;
        $truncated     = false;

        if (is_file($searchPath)) {
            $this->searchFile($searchPath, $regex, $matches, $totalMatches, $filesSearched);
            $truncated = $totalMatches > $this->maxMatches;
        } else {
            $files = $this->collectFiles($searchPath, $include);
            foreach ($files as $file) {
                $this->searchFile($file, $regex, $matches, $totalMatches, $filesSearched);
                if (count($matches) >= $this->maxMatches) {
                    $truncated = true;
                    break;
                }
            }
        }

        $basePath = $this->basePath;
        $baseLen = strlen($basePath) + 1;
        $relativeMatches = array_map(
            static fn(GrepMatchDto $m): GrepMatchDto => new GrepMatchDto(
                filePath: str_starts_with($m->filePath, $basePath)
                    ? substr($m->filePath, $baseLen)
                    : $m->filePath,
                lineNumber: $m->lineNumber,
                lineContent: $m->lineContent,
                matchText: $m->matchText,
            ),
            array_slice($matches, 0, $this->maxMatches),
        );

        $dto = new GrepResultDto(
            pattern      : $pattern,
            matches      : $relativeMatches,
            truncated    : $truncated,
            totalMatches : $totalMatches,
            filesSearched: $filesSearched,
        );

        return JsonHelper::encodeThrow($dto->toArray());
    }

    /**
     * Ищет совпадения паттерна в содержимом файла.
     *
     * @param string         $filePath      Абсолютный путь к файлу
     * @param string         $regex         Подготовленное регулярное выражение
     * @param GrepMatchDto[] $matches       Массив-аккумулятор совпадений (передаётся по ссылке)
     * @param int            $totalMatches  Общий счётчик совпадений (передаётся по ссылке)
     * @param int            $filesSearched Счётчик просканированных файлов (передаётся по ссылке)
     */
    private function searchFile(
        string $filePath,
        string $regex,
        array &$matches,
        int &$totalMatches,
        int &$filesSearched,
    ): void {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $size = filesize($filePath);
        if ($size === false || $size > $this->maxFileSize) {
            return;
        }

        if (!FileSystemHelper::isTextFile($filePath)) {
            return;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        $filesSearched++;
        $lines = explode("\n", $content);

        foreach ($lines as $index => $line) {
            if (count($matches) >= $this->maxMatches) {
                return;
            }

            $lineMatches = [];
            $result = @preg_match_all($regex, $line, $lineMatches, PREG_OFFSET_CAPTURE);

            if ($result === false || $result === 0) {
                continue;
            }

            foreach ($lineMatches[0] as $matchData) {
                $totalMatches++;
                if (count($matches) >= $this->maxMatches) {
                    return;
                }

                $matchText = $matchData[0];
                $truncatedMatch = mb_strlen($matchText) > 200
                    ? mb_substr($matchText, 0, 197) . '...'
                    : $matchText;

                $matches[] = new GrepMatchDto(
                    filePath   : $filePath,
                    lineNumber : $index + 1,
                    lineContent: mb_strlen($line) > 500 ? mb_substr($line, 0, 497) . '...' : $line,
                    matchText  : $truncatedMatch,
                );
            }
        }
    }

    /**
     * Рекурсивно собирает файлы из директории с учётом фильтров.
     *
     * @param string      $directory Директория для обхода
     * @param string|null $include   Glob-шаблон для фильтрации файлов
     *
     * @return string[] Массив абсолютных путей к файлам
     */
    private function collectFiles(string $directory, ?string $include = null): array
    {
        $files = [];
        $entries = @scandir($directory);
        if ($entries === false) {
            return $files;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (FileSystemHelper::shouldExclude($path, $this->excludePatterns)) {
                continue;
            }

            if (is_dir($path)) {
                $files = [...$files, ...$this->collectFiles($path, $include)];
            } elseif (is_file($path)) {
                if ($include !== null && $include !== '' && !fnmatch($include, $entry)) {
                    continue;
                }
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Подготавливает regex-паттерн из входной строки.
     *
     * Если строка уже является корректным regex (с разделителями), использует её как есть.
     * Иначе оборачивает в разделители и экранирует спецсимволы.
     *
     * @param string $pattern Входной паттерн
     *
     * @return string|null Подготовленный regex или null при ошибке
     */
    private function buildRegex(string $pattern): ?string
    {
        if ($pattern === '') {
            return null;
        }

        if (@preg_match($pattern, '') !== false) {
            return $pattern;
        }

        $regex = '/' . str_replace('/', '\/', $pattern) . '/u';
        if (@preg_match($regex, '') !== false) {
            return $regex;
        }

        $escaped = '/' . preg_quote($pattern, '/') . '/u';
        if (@preg_match($escaped, '') !== false) {
            return $escaped;
        }

        return null;
    }

    /**
     * Устанавливает базовую директорию для поиска.
     *
     * Все относительные пути в результатах будут вычислены от этой директории.
     *
     * @param string $basePath Абсолютный путь к корневой директории поиска
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setBasePath(string $basePath): self
    {
        $this->basePath = $basePath;
        return $this;
    }

    /**
     * Устанавливает максимальное количество возвращаемых совпадений.
     *
     * Поиск прекращается при достижении лимита. Поле truncated в результате
     * будет true, если реальных совпадений было больше.
     *
     * @param int $maxMatches Максимальное число совпадений (по умолчанию 50)
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setMaxMatches(int $maxMatches): self
    {
        $this->maxMatches = $maxMatches;
        return $this;
    }

    /**
     * Устанавливает максимальный размер файла для сканирования.
     *
     * Файлы, превышающие этот размер, молча пропускаются.
     * Позволяет избежать загрузки очень больших файлов в память.
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
     * Устанавливает шаблоны для исключения директорий и файлов из сканирования.
     *
     * Каждый сегмент пути проверяется через fnmatch() на соответствие шаблонам.
     *
     * @param string[] $excludePatterns Массив glob-шаблонов для исключения
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setExcludePatterns(array $excludePatterns): self
    {
        $this->excludePatterns = $excludePatterns;
        return $this;
    }

    /**
     * Устанавливает количество строк контекста вокруг каждого совпадения.
     *
     * Аналог флага `-C` в grep. В текущей реализации зарезервировано
     * для будущего использования.
     *
     * @param int $contextLines Число строк контекста до и после совпадения
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setContextLines(int $contextLines): self
    {
        $this->contextLines = $contextLines;
        return $this;
    }
}
