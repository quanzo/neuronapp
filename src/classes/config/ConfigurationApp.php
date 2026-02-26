<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\config;

use app\modules\neuron\helpers\CommentsHelper;
use RuntimeException;

/**
 * Синглтон конфигурации консольного приложения.
 *
 * Отвечает за загрузку и предоставление настроек из файла config.jsonc,
 * расположенного в рабочей директории приложения пользователя.
 */
class ConfigurationApp
{
    /**
     * Единственный экземпляр конфигурации.
     */
    private static ?ConfigurationApp $instance = null;

    /**
     * Абсолютный путь к рабочей директории приложения.
     */
    private string $workDir;

    /**
     * Абсолютный путь к файлу конфигурации config.jsonc.
     */
    private string $configPath;

    /**
     * Ассоциативный массив с настройками приложения.
     *
     * @var array<string, mixed>
     */
    private array $config = [];

    /**
     * Приватный конструктор, выполняющий загрузку конфигурации.
     *
     * @param string $workDir Абсолютный путь к рабочей директории приложения.
     */
    private function __construct(string $workDir)
    {
        $this->workDir = rtrim($workDir, DIRECTORY_SEPARATOR);
        $this->configPath = $this->workDir . DIRECTORY_SEPARATOR . 'config.jsonc';

        $this->load();
    }

    /**
     * Инициализирует синглтон конфигурации.
     *
     * Метод должен вызываться один раз при старте приложения.
     * Повторные вызовы игнорируются.
     *
     * @param string $workDir Абсолютный путь к рабочей директории приложения.
     */
    public static function init(string $workDir): void
    {
        if (self::$instance !== null) {
            return;
        }

        self::$instance = new self($workDir);
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
     * При отсутствии файла используются настройки по умолчанию (пустой массив).
     * При ошибке чтения или разбора JSONC выбрасывается исключение.
     *
     * @throws RuntimeException При ошибке чтения или разбора файла конфигурации.
     */
    private function load(): void
    {
        if (!is_file($this->configPath)) {
            // Файл отсутствует — используем настройки по умолчанию.
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

