<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\config;

use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\producers\AgentProducer;
use app\modules\neuron\classes\producers\SkillProducer;
use app\modules\neuron\classes\producers\TodoListProducer;
use app\modules\neuron\classes\safe\InputSafe;
use app\modules\neuron\classes\safe\OutputSafe;
use app\modules\neuron\classes\safe\rules\input\CollapseRepeatCharsInputRule;
use app\modules\neuron\classes\safe\rules\input\MaxLengthInputRule;
use app\modules\neuron\classes\safe\rules\input\NormalizeWhitespaceInputRule;
use app\modules\neuron\classes\safe\rules\input\RegexInjectionInputRule;
use app\modules\neuron\classes\safe\rules\input\RemoveInvisibleCharsInputRule;
use app\modules\neuron\classes\safe\rules\input\TypoglycemiaInputRule;
use app\modules\neuron\classes\safe\rules\output\RegexLeakOutputRule;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\logger\ContextualLogger;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\helpers\OptionsHelper;
use app\modules\neuron\helpers\SessionKeyHelper;
use app\modules\neuron\helpers\StorageFileHelper;
use app\modules\neuron\traits\LoggerAwareContextualTrait;
use app\modules\neuron\traits\LoggerAwareTrait;
use app\modules\neuron\classes\storage\VarStorage;
use app\modules\neuron\services\config\SessionConfigAppService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Синглтон конфигурации консольного приложения.
 *
 * Отвечает за загрузку и предоставление настроек из файла config.jsonc,
 * искомого в приоритетных директориях через {@see DirPriority}.
 */
class ConfigurationApp
{
    use LoggerAwareTrait;
    use LoggerAwareContextualTrait;

    /**
     * Регулярное выражение для проверки формата полного session key.
     *
     * Источник истины — {@see SessionKeyHelper::PATTERN}. Константа оставлена как
     * совместимый публичный алиас для существующего кода и тестов.
     */
    public const SESSION_KEY_PATTERN = SessionKeyHelper::PATTERN;

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

    /** Хранилище результатов (лениво инициализируется). */
    private ?VarStorage $varStorage = null;

    /** Сервис защиты входящих сообщений LLM (лениво инициализируется). */
    private ?InputSafe $inputSafe = null;

    /** Сервис защиты исходящих сообщений LLM (лениво инициализируется). */
    private ?OutputSafe $outputSafe = null;

    /**
     * Базовый ключ сессии (временна́я часть без имени агента).
     *
     * Генерируется лениво при первом обращении через {@see getSessionKey()}.
     */
    private ?string $sessionKey = null;

    /**
     * Id пользователя
     *
     * @var int|string
     */
    private int|string $userId = 0;

    /**
     * Приватный конструктор, выполняющий загрузку конфигурации.
     *
     * @param DirPriority $dirPriority    Приоритетный список директорий для поиска файла конфигурации.
     * @param string      $configFileName Имя файла конфигурации (например, config.jsonc).
     */
    private function __construct(DirPriority $dirPriority, string $configFileName, int|string $userId = 0)
    {
        $this->dirPriority = $dirPriority;
        $this->configFileName = $configFileName;
        $this->userId = $userId;

        $this->load();

        if (empty($this->userId) && !empty($this->get('userId'))) {
            $this->userId = $this->get('userId');
        }
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
    public static function init(DirPriority $dirPriority, string $configFileName = 'config.jsonc', int|string $userId = 0): void
    {
        if (self::$instance !== null) {
            return;
        }

        self::$instance = new self($dirPriority, $configFileName, $userId);
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
     * Id пользователя
     *
     * @return integer|string
     */
    public function getUserId(): int|string
    {
        return $this->userId;
    }

    /**
     * Возвращает контекст для логирования: имя агента и ключ сессии.
     *
     * @return array{session: string|null}
     */
    public function getLogContext(): array
    {
        return [
            'session' => $this->getSessionKey(),
        ];
    }

    /**
     * Возвращает producer конфигураций агентов (один и тот же экземпляр при повторных вызовах).
     */
    public function getAgentProducer(): AgentProducer
    {
        if ($this->agentProducer === null) {
            $this->agentProducer = new AgentProducer($this->dirPriority, $this);
            $this->agentProducer->logger = $this->getLoggerWithContext();
        }

        return $this->agentProducer;
    }

    /**
     * Возвращает producer списков заданий (один и тот же экземпляр при повторных вызовах).
     */
    public function getTodoListProducer(): TodoListProducer
    {
        if ($this->todoListProducer === null) {
            $this->todoListProducer = new TodoListProducer($this->dirPriority, $this);
        }

        return $this->todoListProducer;
    }

    /**
     * Возвращает producer навыков (один и тот же экземпляр при повторных вызовах).
     */
    public function getSkillProducer(): SkillProducer
    {
        if ($this->skillProducer === null) {
            $this->skillProducer = new SkillProducer($this->dirPriority, $this);
        }

        return $this->skillProducer;
    }

    /**
     * Возвращает имя директории для хранения сессий.
     *
     * @return string Имя папки (например, `.sessions`).
     */
    public static function getSessionDirName(): string
    {
        return '.sessions';
    }

    /**
     * Имя директории логов
     *
     * @return string
     */
    public static function getLogDirName(): string
    {
        return '.logs';
    }

    /**
     * Возвращает короткое имя директории долговременной памяти сообщений (Markdown).
     *
     * @return string Имя поддиректории (например, `.mind`).
     */
    public static function getMindDirName(): string
    {
        return '.mind';
    }

    /**
     * Возвращает полный путь к директории хранения сессий.
     *
     * Путь формируется как {@see APP_WORK_DIR} + разделитель + {@see getSessionDirName()}.
     *
     * @return string Абсолютный путь к папке сессий.
     */
    public function getSessionDir(): string
    {
        return $this->dirPriority->resolveDir(self::getSessionDirName());
    }

    /**
     * Возвращает полный путь к директории хранения логов.
     *
     * @return string Абсолютный путь к папке сессий.
     */
    public function getLogDir(): string
    {
        return $this->dirPriority->resolveDir(self::getLogDirName());
    }

    /**
     * Возвращает директорию старта приложения.
     *
     * По соглашению проекта директория старта — это **самая приоритетная** базовая
     * директория в {@see DirPriority}, которая задаётся при bootstrap (обычно это
     * {@see getcwd()} на момент запуска `bin/console.php`).
     *
     * @return string Абсолютный путь к директории старта.
     *
     * @throws RuntimeException Если директория старта не определена.
     */
    public function getStartDir(): string
    {
        $path = $this->dirPriority->resolveDir('');
        if ($path === null || $path === '') {
            throw new RuntimeException('Директория старта не определена.');
        }
        return $path;
    }

    /**
     * Возвращает полный путь к директории долговременной памяти сообщений.
     *
     * Путь формируется через {@see DirPriority::resolveDir()} для {@see getMindDirName()}.
     *
     * @return string Абсолютный путь к папке `.mind`.
     */
    public function getMindDir(): string
    {
        $path = $this->dirPriority->resolveDir(self::getMindDirName());
        if ($path === null || $path === '') {
            throw new RuntimeException('Директория долговременной памяти (.mind) не найдена.');
        }

        return $path;
    }

    /**
     * Разрешён ли сбор данных для долговременной памяти `.mind`.
     *
     * Читается из `config.jsonc` ключом `mind.collect` (вложенный объект `mind`, поле `collect`).
     * Значения `1`, `true`, строка `'true'` трактуются как включено; `0`, `false`, строка `'false'` — как выключено.
     * При отсутствии ключа по умолчанию сбор **включён** (обратная совместимость).
     * Подписчик {@see \app\modules\neuron\classes\events\subscribers\LongTermMindSubscriber} при выключенной опции
     * не записывает сообщения в файлы `.mind` (см. `docs/mind.md`).
     *
     * Пример в `config.jsonc`:
     * ```jsonc
     * { "mind": { "collect": false } }
     * ```
     */
    public function isLongTermMindCollectionEnabled(): bool
    {
        return OptionsHelper::toBool($this->get('mind.collect', true));
    }

    /**
     * Возвращает короткое имя директории хранилища состояния run (чекпоинты).
     *
     * @return string Имя поддиректории (например, .store).
     */
    public static function getStoreDirName(): string
    {
        return '.store';
    }

    /**
     * Возвращает полный путь к директории хранилища состояния run (чекпоинты).
     *
     * Путь формируется через {@see DirPriority::resolveDir()} для {@see getStoreDirName()}.
     * Директория создаётся при старте приложения в bin/console.php.
     *
     * @return string Абсолютный путь к папке .store.
     * @throws RuntimeException Если директория не найдена.
     */
    public function getStoreDir(): string
    {
        $path = $this->dirPriority->resolveDir(self::getStoreDirName());
        if ($path === null || $path === '') {
            throw new RuntimeException('Директория хранилища чекпоинтов (.store) не найдена.');
        }
        return $path;
    }

    /**
     * Возвращает объект хранилища результатов для директории .store.
     */
    public function getVarStorage(): VarStorage
    {
        if ($this->varStorage === null) {
            $this->varStorage = new VarStorage($this->getStoreDir());
        }
        return $this->varStorage;
    }

    /**
     * Возвращает сервис защиты входных сообщений LLM.
     */
    public function getInputSafe(): InputSafe
    {
        if ($this->inputSafe === null) {
            $this->inputSafe = $this->buildDefaultInputSafe();
        }

        return $this->inputSafe;
    }

    /**
     * Заменяет сервис защиты входных сообщений.
     */
    public function setInputSafe(InputSafe $inputSafe): self
    {
        $this->inputSafe = $inputSafe;
        return $this;
    }

    /**
     * Возвращает сервис защиты исходящих сообщений LLM.
     */
    public function getOutputSafe(): OutputSafe
    {
        if ($this->outputSafe === null) {
            $this->outputSafe = $this->buildDefaultOutputSafe();
        }

        return $this->outputSafe;
    }

    /**
     * Заменяет сервис защиты исходящих сообщений.
     */
    public function setOutputSafe(OutputSafe $outputSafe): self
    {
        $this->outputSafe = $outputSafe;
        return $this;
    }

    /**
     * Собирает дефолтный пайплайн InputSafe.
     */
    private function buildDefaultInputSafe(): InputSafe
    {
        $maxLength = (int) $this->get('safe.input.max_length', 20000);
        if ($maxLength <= 0) {
            $maxLength = 20000;
        }

        return new InputSafe(
            [
                new RemoveInvisibleCharsInputRule(),
                new NormalizeWhitespaceInputRule(),
                new CollapseRepeatCharsInputRule(5),
                new MaxLengthInputRule($maxLength),
            ],
            [
                new RegexInjectionInputRule(
                    'instruction_override',
                    'Input tries to override system instructions.',
                    '/\b(ignore|disregard|forget)\b.{0,40}\b(previous|system|earlier)\b.{0,40}\b(instructions?|prompt)\b/iu'
                ),
                new RegexInjectionInputRule(
                    'system_prompt_exfiltration',
                    'Input requests hidden/system prompt disclosure.',
                    '/\b(reveal|show|print|dump|leak|expose)\b.{0,40}\b(system prompt|hidden instructions?|internal instructions?)\b/iu'
                ),
                new RegexInjectionInputRule(
                    'jailbreak_role_hijack',
                    'Input contains role-hijack or jailbreak markers.',
                    '/\b(developer mode|dan\b|do anything now|bypass safety|disable guardrails?|jailbreak)\b/iu'
                ),
                new TypoglycemiaInputRule(),
            ]
        );
    }

    /**
     * Собирает дефолтный пайплайн OutputSafe.
     */
    private function buildDefaultOutputSafe(): OutputSafe
    {
        return new OutputSafe(
            [
                new RegexLeakOutputRule(
                    'system_prompt_leak',
                    'Output may disclose hidden/system prompt.',
                    '/\b(system prompt|hidden instructions?|internal instructions?)\b.{0,200}/iu',
                    '[REDACTED_SYSTEM_PROMPT_FRAGMENT]'
                ),
                new RegexLeakOutputRule(
                    'api_key_leak',
                    'Output may disclose API/token secret.',
                    '/\b(sk-[a-z0-9]{16,}|api[_-]?key\s*[:=]\s*[a-z0-9_\-]{10,}|bearer\s+[a-z0-9\._\-]{20,})\b/iu',
                    '[REDACTED_SECRET]'
                ),
            ]
        );
    }

    private $_sessionSrv = null;

    /**
     * Сервис по упроавлению сессиями
     *
     * @return SessionConfigAppService
     */
    public function getSessionService(): SessionConfigAppService
    {
        if (empty($this->_sessionSrv)) {
            $this->_sessionSrv = new SessionConfigAppService($this);
        }
        return $this->_sessionSrv;
    }

    /**
     * Проверяет, существует ли файл сессии для заданного ключа и агента.
     *
     * Использует тот же формат имени файла, что и {@see \NeuronAI\Chat\History\FileChatHistory}
     * (префикс neuron_, расширение .chat).
     *
     * @param string $sessionKey Базовый ключ сессии (формат buildSessionKey()).
     * @param string|null $agentName  Имя агента.
     *
     * @return bool true, если файл сессии существует.
     */
    public function sessionExists(string $sessionKey, ?string $agentName = null): bool
    {
        $path = $this->dirPriority->resolveFile(
            $this->getSessionDirName() . DIRECTORY_SEPARATOR . StorageFileHelper::sessionHistoryFileName($sessionKey, $agentName)
        );
        if ($path) {
            return true;
        }
        return false;
    }

    /**
     * Формирует уникальный базовый ключ сессии на основе текущего microtime.
     *
     * Формат: `YYYYMMDD-HHMMSS-μs` (без имени агента — агент добавляет
     * свою часть самостоятельно в {@see ConfigurationAgent}).
     */
    public static function buildSessionKey(): string
    {
        return SessionKeyHelper::buildBaseKey();
    }

    /**
     * Возвращает текущий базовый ключ сессии.
     *
     * При первом вызове лениво генерирует ключ через {@see buildSessionKey()}.
     */
    public function getSessionKey(): string
    {
        if ($this->sessionKey === null) {
            $this->sessionKey = SessionKeyHelper::buildSessionKey($this->getUserId());
        }

        return $this->sessionKey;
    }

    /**
     * Устанавливает базовый ключ сессии.
     */
    public function setSessionKey(string $sessionKey): void
    {
        $this->sessionKey = $sessionKey;
    }

    /**
     * Проверка ключа сессии на корректность
     *
     * @param string $sessionKey
     * @return boolean
     */
    public static function isValidSessionKey(string $sessionKey): bool
    {
        return SessionKeyHelper::isValid($sessionKey);
    }

    /**
     * Возвращает описание канонического формата session key для CLI и docs.
     *
     * @return string Строка с форматом и примером.
     */
    public static function describeSessionKeyFormat(): string
    {
        return SessionKeyHelper::describeFormat();
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

        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $value = $this->config;

            foreach ($parts as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }

                $value = $value[$segment];
            }

            return $value;
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

        $this->config = JsonHelper::decodeAssociativeForConfigFile($cleanJson, $this->configPath);
    }
}
