<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\config;

use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\helpers\CommentsHelper;
use RuntimeException;

/**
 * Синглтон конфигурации консольного приложения.
 *
 * Отвечает за загрузку и предоставление настроек из файла config.jsonc,
 * искомого в приоритетных директориях через {@see DirPriority}.
 */
class ConfigurationApp
{
    /**
     * Единственный экземпляр конфигурации.
     */
    private static ?ConfigurationApp $instance = null;

    /**
     * Приоритетный список директорий для поиска файлов конфигурации.
     */
    private DirPriority $dirPriority;

    /**
     * Абсолютный путь к файлу конфигурации config.jsonc (определяется при загрузке).
     */
    private ?string $configPath = null;

    /**
     * Ассоциативный массив с настройками приложения.
     *
     * @var array<string, mixed>
     */
    private array $config = [];

    /**
     * Приватный конструктор, выполняющий загрузку конфигурации.
     *
     * @param DirPriority $dirPriority Приоритетный список директорий для поиска config.jsonc.
     */
    private function __construct(DirPriority $dirPriority)
    {
        $this->dirPriority = $dirPriority;

        $this->load();
    }

    /**
     * Инициализирует синглтон конфигурации.
     *
     * Метод должен вызываться один раз при старте приложения.
     * Повторные вызовы игнорируются.
     *
     * @param DirPriority $dirPriority Приоритетный список директорий для поиска config.jsonc.
     */
    public static function init(DirPriority $dirPriority): void
    {
        if (self::$instance !== null) {
            return;
        }

        self::$instance = new self($dirPriority);
    }

    /**
     * Возвращает единственный экземпляр конфигурации.
     *
     * @throws RuntimeException Если конфигурация не была инициализирована через init().
     */
    public static function getInstance(): ConfigurationApp
    {
        if (self::$instance === null) {
            throw new RuntimeException('ConfigurationApp is not initialized. Call ConfigurationApp::init() first.');
        }

        return self::$instance;
    }

    /**
     * Возвращает приоритетный список директорий, используемый для поиска конфигурации.
     */
    public function getDirPriority(): DirPriority
    {
        return $this->dirPriority;
    }

    /**
     * Возвращает все настройки конфигурации в виде массива.
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        return $this->config;
    }

    /**
     * Возвращает значение настройки по ключу или значение по умолчанию.
     *
     * @param string $key     Имя настройки.
     * @param mixed  $default Значение по умолчанию, если ключ отсутствует.
     *
     * @return mixed Значение настройки либо значение по умолчанию.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        return $default;
    }

    /**
     * Загружает конфигурацию из файла config.jsonc.
     *
     * Файл ищется в директориях через {@see DirPriority}. При отсутствии файла
     * используются настройки по умолчанию (пустой массив). При ошибке чтения или
     * разбора JSONC выбрасывается исключение.
     *
     * @throws RuntimeException При ошибке чтения или разбора файла конфигурации.
     */
    private function load(): void
    {
        $this->configPath = $this->dirPriority->resolveFile('config.jsonc');

        if ($this->configPath === null) {
            // Файл не найден в приоритетных директориях — используем настройки по умолчанию.
            $this->config = [];

            return;
        }

        $contents = @file_get_contents($this->configPath);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read configuration file: %s', $this->configPath));
        }

        // Убираем комментарии формата JSONC, чтобы получить валидный JSON.
        $cleanJson = CommentsHelper::stripComments($contents);

        $decoded = json_decode($cleanJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(sprintf(
                'Invalid JSONC in configuration file %s: %s',
                $this->configPath,
                json_last_error_msg()
            ));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'Configuration file %s must decode to an associative array.',
                $this->configPath
            ));
        }

        $this->config = $decoded;
    }
}

