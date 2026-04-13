<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\classes\dto\tools\EditResultDto;
use app\modules\neuron\helpers\FileSystemHelper;
use app\modules\neuron\tools\ATool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function copy;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function is_dir;
use function is_file;
use function is_writable;
use function mkdir;
use function rename;
use function sprintf;
use function str_replace;
use function substr_count;
use function tempnam;

/**
 * Инструмент редактирования файлов с точечной заменой текста.
 *
 * Позволяет LLM вносить изменения в существующие файлы или создавать новые.
 * Основной инструмент для автоматического рефакторинга, исправления ошибок,
 * добавления кода. Обычно используется после {@see ViewTool}, когда модель
 * уже ознакомилась с содержимым файла и может точно сформировать old_string.
 *
 * Алгоритм работы:
 * 1. Путь проверяется через {@see FileSystemHelper::isPathSafe()} для защиты
 *    от path-traversal.
 * 2. Для существующего файла — ищется **ровно одно** вхождение old_string.
 *    Если вхождений 0 или >1, возвращается ошибка. Это предотвращает
 *    случайные множественные замены.
 * 3. Если файл не существует и createIfNotExists = true, а old_string пуст,
 *    создаётся новый файл с содержимым new_string (включая промежуточные директории).
 * 4. При createBackup = true перед записью создаётся резервная копия `.bak`.
 * 5. Запись выполняется атомарно: данные записываются во временный файл,
 *    затем rename() заменяет оригинал. Это защищает от повреждения при сбое.
 *
 * Все ограничения и настройки вынесены в свойства класса и могут быть изменены
 * через конструктор или сеттеры: basePath, createBackup, createIfNotExists, maxFileSize.
 *
 * Результат возвращается в виде JSON через {@see EditResultDto}.
 *
 * @see EditResultDto    Структура результата редактирования
 * @see ViewTool         Обычно вызывается перед Edit для получения контекста
 * @see FileSystemHelper Проверка пути и безопасности
 */
class EditTool extends ATool
{
    /** @var string Базовая директория для разрешения путей */
    protected string $basePath;

    /** @var bool Создавать ли резервную копию перед редактированием */
    protected bool $createBackup = true;

    /** @var bool Разрешить создание нового файла, если он не существует */
    protected bool $createIfNotExists = false;

    /** @var int Максимальный размер файла в байтах */
    protected int $maxFileSize = 1048576;

    /**
     * @param string $basePath         Базовая директория
     * @param bool   $createBackup     Создавать резервную копию
     * @param bool   $createIfNotExists Разрешить создание новых файлов
     * @param int    $maxFileSize      Максимальный размер файла (байт)
     * @param string $name             Имя инструмента
     * @param string $description      Описание инструмента
     */
    public function __construct(
        string $basePath = '',
        bool $createBackup = true,
        bool $createIfNotExists = false,
        int $maxFileSize = 1048576,
        string $name = 'edit',
        string $description = 'Редактирование файла: находит указанный фрагмент текста и заменяет его. Фрагмент для замены должен быть уникальным в файле.',
    ) {
        parent::__construct(name: $name, description: $description);

        // basePath по умолчанию будет подставлен из ConfigurationApp::getStartDir()
        // в {@see ATool::setAgentCfg()}.
        $this->basePath = $basePath;
        $this->createBackup = $createBackup;
        $this->createIfNotExists = $createIfNotExists;
        $this->maxFileSize = $maxFileSize;
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
                description: 'Путь к файлу для редактирования (абсолютный или относительный).',
                required: true,
            ),
            ToolProperty::make(
                name: 'old_string',
                type: PropertyType::STRING,
                description: 'Фрагмент текста, который нужно заменить. Должен быть уникальным в файле. Для создания нового файла — передать пустую строку.',
                required: true,
            ),
            ToolProperty::make(
                name: 'new_string',
                type: PropertyType::STRING,
                description: 'Текст, на который заменить найденный фрагмент. Для нового файла — полное содержимое.',
                required: true,
            ),
        ];
    }

    /**
     * Выполняет редактирование файла.
     *
     * Находит ровно одно вхождение old_string и заменяет на new_string.
     * Если файл не существует и createIfNotExists=true, а old_string пуст — создаёт файл.
     * Запись атомарна: данные записываются во временный файл, затем rename().
     *
     * @param string $path       Путь к файлу
     * @param string $old_string Текст для замены
     * @param string $new_string Текст замены
     *
     * @return string JSON-строка с результатом операции
     */
    public function __invoke(string $path, string $old_string, string $new_string): string
    {
        $resolvedPath = FileSystemHelper::resolvePath($this->basePath, $path);

        if (!FileSystemHelper::isPathSafe($resolvedPath, $this->basePath)) {
            return $this->errorResult($path, 'Доступ запрещён: путь выходит за пределы базовой директории.');
        }

        if (!is_file($resolvedPath)) {
            return $this->handleNewFile($resolvedPath, $path, $old_string, $new_string);
        }

        return $this->handleExistingFile($resolvedPath, $path, $old_string, $new_string);
    }

    /**
     * Обработка случая, когда файл не существует.
     *
     * @param string $resolvedPath Абсолютный путь к файлу
     * @param string $displayPath  Отображаемый путь
     * @param string $oldString    Текст для замены
     * @param string $newString    Текст замены (содержимое нового файла)
     *
     * @return string JSON-строка с результатом
     */
    private function handleNewFile(string $resolvedPath, string $displayPath, string $oldString, string $newString): string
    {
        if (!$this->createIfNotExists) {
            return $this->errorResult($displayPath, "Файл '{$displayPath}' не существует, а создание новых файлов отключено.");
        }

        if ($oldString !== '') {
            return $this->errorResult($displayPath, "Файл '{$displayPath}' не существует. Для создания нового файла old_string должен быть пустым.");
        }

        $dir = dirname($resolvedPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return $this->errorResult($displayPath, "Не удалось создать директорию для файла '{$displayPath}'.");
            }
        }

        if ($this->atomicWrite($resolvedPath, $newString) === false) {
            return $this->errorResult($displayPath, "Не удалось записать файл '{$displayPath}'.");
        }

        $dto = new EditResultDto(
            filePath: $displayPath,
            success: true,
            replacements: 0,
            message: "Файл '{$displayPath}' успешно создан.",
        );

        return JsonHelper::encodeThrow($dto->toArray());
    }

    /**
     * Обработка редактирования существующего файла.
     *
     * @param string $resolvedPath Абсолютный путь к файлу
     * @param string $displayPath  Отображаемый путь
     * @param string $oldString    Текст для замены
     * @param string $newString    Текст замены
     *
     * @return string JSON-строка с результатом
     */
    private function handleExistingFile(string $resolvedPath, string $displayPath, string $oldString, string $newString): string
    {
        if (!is_writable($resolvedPath)) {
            return $this->errorResult($displayPath, "Файл '{$displayPath}' недоступен для записи.");
        }

        $size = filesize($resolvedPath);
        if ($size === false || $size > $this->maxFileSize) {
            return $this->errorResult($displayPath, sprintf(
                "Файл '%s' слишком большой (%d байт). Максимум: %d байт.",
                $displayPath,
                $size ?: 0,
                $this->maxFileSize,
            ));
        }

        $content = file_get_contents($resolvedPath);
        if ($content === false) {
            return $this->errorResult($displayPath, "Не удалось прочитать файл '{$displayPath}'.");
        }

        if ($oldString === '') {
            return $this->errorResult($displayPath, "Параметр old_string не может быть пустым для существующего файла.");
        }

        $occurrences = substr_count($content, $oldString);

        if ($occurrences === 0) {
            return $this->errorResult($displayPath, "Фрагмент old_string не найден в файле '{$displayPath}'.");
        }

        if ($occurrences > 1) {
            return $this->errorResult($displayPath, sprintf(
                "Фрагмент old_string найден %d раз в файле '%s'. Требуется ровно одно вхождение. Увеличьте контекст для уникальной идентификации.",
                $occurrences,
                $displayPath,
            ));
        }

        if ($this->createBackup) {
            if (!@copy($resolvedPath, $resolvedPath . '.bak')) {
                return $this->errorResult($displayPath, "Не удалось создать резервную копию файла '{$displayPath}'.");
            }
        }

        $newContent = str_replace($oldString, $newString, $content);

        if ($this->atomicWrite($resolvedPath, $newContent) === false) {
            return $this->errorResult($displayPath, "Не удалось записать изменения в файл '{$displayPath}'.");
        }

        $dto = new EditResultDto(
            filePath: $displayPath,
            success: true,
            replacements: 1,
            message: "Файл '{$displayPath}' успешно отредактирован.",
        );

        return JsonHelper::encodeThrow($dto->toArray());
    }

    /**
     * Атомарная запись в файл через временный файл и rename().
     *
     * @param string $targetPath Путь к целевому файлу
     * @param string $content    Содержимое для записи
     *
     * @return bool Успешность операции
     */
    private function atomicWrite(string $targetPath, string $content): bool
    {
        $dir = dirname($targetPath);
        $tmpFile = @tempnam($dir, '.edit_tmp_');
        if ($tmpFile === false) {
            return false;
        }

        if (file_put_contents($tmpFile, $content) === false) {
            @unlink($tmpFile);
            return false;
        }

        if (!@rename($tmpFile, $targetPath)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }

    /**
     * Формирует JSON-строку с ошибкой через EditResultDto.
     *
     * @param string $filePath Путь к файлу
     * @param string $message  Сообщение об ошибке
     *
     * @return string JSON-строка
     */
    private function errorResult(string $filePath, string $message): string
    {
        $dto = new EditResultDto(
            filePath: $filePath,
            success: false,
            replacements: 0,
            message: $message,
        );

        return JsonHelper::encodeThrow($dto->toArray());
    }

    /**
     * Устанавливает базовую директорию для разрешения относительных путей.
     *
     * Также используется для проверки path-traversal: редактируемый файл
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
     * Определяет, следует ли создавать резервную копию перед редактированием.
     *
     * При true перед каждой заменой создаётся файл `{path}.bak`
     * с оригинальным содержимым.
     *
     * @param bool $createBackup true — создавать .bak-копию (по умолчанию true)
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setCreateBackup(bool $createBackup): self
    {
        $this->createBackup = $createBackup;
        return $this;
    }

    /**
     * Определяет, можно ли создавать новые файлы через этот инструмент.
     *
     * При true и пустом old_string для несуществующего файла будет создан
     * новый файл с содержимым new_string. Промежуточные директории
     * также создаются автоматически (mkdir recursive).
     *
     * @param bool $createIfNotExists true — разрешить создание новых файлов (по умолчанию false)
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setCreateIfNotExists(bool $createIfNotExists): self
    {
        $this->createIfNotExists = $createIfNotExists;
        return $this;
    }

    /**
     * Устанавливает максимальный размер файла, допустимый для редактирования.
     *
     * Файлы, превышающие этот размер, будут отклонены с информативным сообщением.
     * Предотвращает загрузку очень больших файлов в память для замены.
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
}
