<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\config;

use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\producers\AgentProducer;
use app\modules\neuron\classes\producers\SkillProducer;
use app\modules\neuron\classes\producers\TodoListProducer;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\ConfigurationAgent;
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
     * Имя файла конфигурации (например, config.jsonc).
     */
    private string $configFileName;

    /**
     * Абсолютный путь к файлу конфигурации (определяется при загрузке).
     */
    private ?string $configPath = null;

    /**
     * Ассоциативный массив с настройками приложения.
     *
     * @var array<string, mixed>
     */
    private array $config = [];

    /** Producer конфигураций агентов (создаётся при первом обращении). */
    private ?AgentProducer $agentProducer = null;

    /** Producer списков заданий (создаётся при первом обращении). */
    private ?TodoListProducer $todoListProducer = null;

    /** Producer навыков (создаётся при первом обращении). */
    private ?SkillProducer $skillProducer = null;

    /**
     * Приватный конструктор, выполняющий загрузку конфигурации.
     *
     * @param DirPriority $dirPriority    Приоритетный список директорий для поиска файла конфигурации.
     * @param string      $configFileName Имя файла конфигурации (например, config.jsonc).
     */
    private function __construct(DirPriority $dirPriority, string $configFileName)
    {
        $this->dirPriority = $dirPriority;
        $this->configFileName = $configFileName;

        $this->load();
    }

    /**
     * Инициализирует синглтон конфигурации.
     *
     * Метод должен вызываться один раз при старте приложения.
     * Повторные вызовы игнорируются.
     *
     * @param DirPriority $dirPriority    Приоритетный список директорий для поиска файла конфигурации.
     * @param string      $configFileName Имя файла конфигурации (например, config.jsonc).
     */
    public static function init(DirPriority $dirPriority, string $configFileName = 'config.jsonc'): void
    {
        if (self::$instance !== null) {
            return;
        }

        self::$instance = new self($dirPriority, $configFileName);
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
     * Возвращает producer конфигураций агентов (один и тот же экземпляр при повторных вызовах).
     */
    public function getAgentProducer(): AgentProducer
    {
        if ($this->agentProducer === null) {
            $this->agentProducer = new AgentProducer($this->dirPriority);
        }

        return $this->agentProducer;
    }

    /**
     * Возвращает producer списков заданий (один и тот же экземпляр при повторных вызовах).
     */
    public function getTodoListProducer(): TodoListProducer
    {
        if ($this->todoListProducer === null) {
            $this->todoListProducer = new TodoListProducer($this->dirPriority);
        }

        return $this->todoListProducer;
    }

    /**
     * Возвращает producer навыков (один и тот же экземпляр при повторных вызовах).
     */
    public function getSkillProducer(): SkillProducer
    {
        if ($this->skillProducer === null) {
            $this->skillProducer = new SkillProducer($this->dirPriority);
        }

        return $this->skillProducer;
    }

    /**
     * Возвращает конфигурацию агента по имени.
     *
     * @param string $name Имя агента (соответствует имени файла без расширения).
     *
     * @return ConfigurationAgent|null Экземпляр конфигурации агента или null.
     */
    public function getAgent(string $name): ?ConfigurationAgent
    {
        return $this->getAgentProducer()->get($name);
    }

    /**
     * Возвращает список заданий по имени.
     *
     * @param string $name Имя списка (соответствует имени файла без расширения).
     *
     * @return TodoList|null Экземпляр списка заданий или null.
     */
    public function getTodoList(string $name): ?TodoList
    {
        return $this->getTodoListProducer()->get($name);
    }

    /**
     * Возвращает навык по имени.
     *
     * @param string $name Имя навыка (соответствует имени файла без расширения).
     *
     * @return Skill|null Экземпляр навыка или null.
     */
    public function getSkill(string $name): ?Skill
    {
        return $this->getSkillProducer()->get($name);
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
        $this->configPath = $this->dirPriority->resolveFile($this->configFileName);

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

