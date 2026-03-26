<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\GlobResultDto;
use app\modules\neuron\helpers\FileSystemHelper;
use app\modules\neuron\tools\ATool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_slice;
use function array_values;
use function count;
use function getcwd;
use function glob;
use function is_dir;
use function natsort;
use function scandir;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const GLOB_NOSORT;

/**
 * Инструмент поиска файлов и директорий по glob-шаблону.
 *
 * Рекурсивно обходит файловую систему от заданной базовой директории,
 * находя файлы, соответствующие переданному паттерну (например `**\/*.php`).
 * Используется LLM для быстрого получения списка релевантных файлов
 * перед вызовом {@see ViewTool} или {@see GrepTool}.
 *
 * Поддерживает паттерны с рекурсивным обходом (`**\/`), подстановочные символы
 * `*` и `?`. По умолчанию исключает служебные директории (.git, node_modules, vendor)
 * и не следует символическим ссылкам для безопасности.
 *
 * Все ограничения и настройки вынесены в свойства класса и могут быть изменены
 * через конструктор или сеттеры: basePath, maxResults, excludePatterns,
 * followSymlinks, respectGitignore.
 *
 * Результат возвращается в виде JSON через {@see GlobResultDto}.
 *
 * @see GlobResultDto Структура результата поиска
 * @see FileSystemHelper Вспомогательные методы для работы с файловой системой
 */
class GlobTool extends ATool
{
    /** @var string Базовая директория для поиска */
    protected string $basePath;

    /** @var int Максимальное количество возвращаемых результатов */
    protected int $maxResults = 1000;

    /** @var string[] Шаблоны для исключения директорий/файлов */
    protected array $excludePatterns = ['.git', 'node_modules', 'vendor'];

    /** @var bool Следовать ли за символическими ссылками */
    protected bool $followSymlinks = false;

    /** @var bool Учитывать ли правила .gitignore */
    protected bool $respectGitignore = false;

    /**
     * @param string      $basePath        Базовая директория для поиска
     * @param int         $maxResults      Максимальное число результатов
     * @param string[]    $excludePatterns Шаблоны исключений
     * @param bool        $followSymlinks  Следовать за символическими ссылками
     * @param bool        $respectGitignore Учитывать .gitignore
     * @param string      $name            Имя инструмента
     * @param string      $description     Описание инструмента
     */
    public function __construct(
        string $basePath = '',
        int $maxResults = 1000,
        array $excludePatterns = ['.git', 'node_modules', 'vendor'],
        bool $followSymlinks = false,
        bool $respectGitignore = false,
        string $name = 'glob',
        string $description = 'Поиск файлов и директорий по glob-шаблону. Поддерживает рекурсивный обход с помощью **, подстановочные символы * и ?.',
    ) {
        parent::__construct(name: $name, description: $description);

        $this->basePath = $basePath !== '' ? $basePath : (string) getcwd();
        $this->maxResults = $maxResults;
        $this->excludePatterns = $excludePatterns;
        $this->followSymlinks = $followSymlinks;
        $this->respectGitignore = $respectGitignore;
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
                description: 'Glob-шаблон для поиска файлов (например, "**/*.php", "src/**/*.test.js", "*.txt").',
                required   : true,
            ),
        ];
    }

    /**
     * Выполняет поиск файлов по glob-шаблону.
     *
     * Рекурсивно обходит файловую систему начиная с basePath, применяет
     * фильтрацию по исключениям и ограничениям, возвращает результат в JSON.
     *
     * @param string $pattern Glob-шаблон для поиска
     *
     * @return string JSON-строка с результатом поиска
     */
    public function __invoke(string $pattern): string
    {
        if (!is_dir($this->basePath)) {
            return json_encode([
                'error' => "Базовая директория '{$this->basePath}' не существует.",
            ], JSON_UNESCAPED_UNICODE);
        }

        $isRecursive = str_starts_with($pattern, '**/');
        $searchPattern = $isRecursive ? substr($pattern, 3) : $pattern;

        $allMatches = $this->findFiles($this->basePath, $searchPattern, $isRecursive);
        natsort($allMatches);
        $allMatches = array_values($allMatches);

        $totalFound = count($allMatches);
        $truncated = $totalFound > $this->maxResults;

        $files = $truncated
            ? array_slice($allMatches, 0, $this->maxResults)
            : $allMatches;

        $baseLen = strlen($this->basePath) + 1;
        $relativePaths = array_map(
            fn(string $path): string => substr($path, $baseLen),
            $files,
        );

        $dto = new GlobResultDto(
            pattern: $pattern,
            basePath: $this->basePath,
            files: $relativePaths,
            truncated: $truncated,
            totalFound: $totalFound,
        );

        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Рекурсивный обход директории с поиском файлов по glob-шаблону.
     *
     * @param string $directory    Текущая директория для обхода
     * @param string $pattern      Glob-шаблон (без префикса **\/)
     * @param bool   $recursive    Выполнять ли рекурсивный обход
     *
     * @return string[] Массив абсолютных путей найденных файлов
     */
    private function findFiles(string $directory, string $pattern, bool $recursive): array
    {
        $files = [];

        $globResults = glob($directory . DIRECTORY_SEPARATOR . $pattern, GLOB_NOSORT);
        if ($globResults !== false) {
            foreach ($globResults as $path) {
                if (!$this->followSymlinks && FileSystemHelper::isSymlink($path)) {
                    continue;
                }
                if (FileSystemHelper::shouldExclude($path, $this->excludePatterns)) {
                    continue;
                }
                $files[] = $path;
            }
        }

        if ($recursive) {
            $entries = @scandir($directory);
            if ($entries === false) {
                return $files;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $subPath = $directory . DIRECTORY_SEPARATOR . $entry;

                if (!is_dir($subPath)) {
                    continue;
                }

                if (!$this->followSymlinks && FileSystemHelper::isSymlink($subPath)) {
                    continue;
                }

                if (FileSystemHelper::shouldExclude($subPath, $this->excludePatterns)) {
                    continue;
                }

                $files = [...$files, ...$this->findFiles($subPath, $pattern, true)];
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Устанавливает базовую директорию для поиска.
     *
     * Все найденные пути будут относительны этой директории.
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
     * Устанавливает максимальное количество возвращаемых результатов.
     *
     * При превышении лимита результат будет усечён, а поле truncated в DTO — true.
     *
     * @param int $maxResults Максимальное число файлов в результате (по умолчанию 1000)
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setMaxResults(int $maxResults): self
    {
        $this->maxResults = $maxResults;
        return $this;
    }

    /**
     * Устанавливает шаблоны для исключения директорий и файлов из результатов.
     *
     * Каждый элемент пути проверяется через fnmatch() на соответствие шаблонам.
     * Например, ['.git', 'node_modules'] исключит любые пути, содержащие эти имена.
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
     * Определяет, следует ли переходить по символическим ссылкам при обходе.
     *
     * По умолчанию false — символические ссылки пропускаются для безопасности,
     * чтобы избежать зацикливания и выхода за пределы рабочей директории.
     *
     * @param bool $followSymlinks true — следовать за символическими ссылками
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setFollowSymlinks(bool $followSymlinks): self
    {
        $this->followSymlinks = $followSymlinks;
        return $this;
    }

    /**
     * Определяет, следует ли учитывать правила .gitignore при фильтрации.
     *
     * Если true, файлы, перечисленные в .gitignore, будут исключены из результатов.
     * В текущей реализации зарезервировано для будущего использования.
     *
     * @param bool $respectGitignore true — учитывать .gitignore
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setRespectGitignore(bool $respectGitignore): self
    {
        $this->respectGitignore = $respectGitignore;
        return $this;
    }
}
