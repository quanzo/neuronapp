<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\config;

use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\producers\AgentProducer;
use app\modules\neuron\classes\producers\SkillProducer;
use app\modules\neuron\classes\producers\TodoListProducer;
use app\modules\neuron\classes\safe\InputSafe;
use app\modules\neuron\classes\safe\OutputSafe;
use app\modules\neuron\classes\safe\RuleFilter;
use app\modules\neuron\classes\safe\enums\RuleSeverityEnum;
use app\modules\neuron\classes\safe\rules\input\CollapseRepeatCharsInputRule;
use app\modules\neuron\classes\safe\rules\input\MaxLengthInputRule;
use app\modules\neuron\classes\safe\rules\input\NormalizeWhitespaceInputRule;
use app\modules\neuron\classes\safe\rules\input\RegexInjectionInputRule;
use app\modules\neuron\classes\safe\rules\input\RemoveInvisibleCharsInputRule;
use app\modules\neuron\classes\safe\rules\input\TypoglycemiaInputRule;
use app\modules\neuron\classes\safe\rules\output\RegexLeakOutputRule;
use app\modules\neuron\classes\safe\tools\DefaultBashToolPolicy;
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
     *
     * @return InputSafe Сервис входной защиты с учётом `safe.input.*`.
     */
    private function buildDefaultInputSafe(): InputSafe
    {
        // App-level флаг полностью выключает входной pipeline. Agent-level `safeInput=false`
        // по-прежнему работает выше, в `ConfigurationAgent`, и не создаёт декоратор проверки для конкретного агента.
        if (!OptionsHelper::toBool($this->get('safe.input.enabled', true))) {
            return new InputSafe([], []);
        }

        // Ограничение длины — sanitize-правило, а не detector: оно не блокирует запрос,
        // а обрезает чрезмерно большой payload до безопасного лимита контекстного окна.
        $maxLength = (int) $this->get('safe.input.max_length', 20000);
        if ($maxLength <= 0) {
            $maxLength = 20000;
        }

        // Фильтр применяется и к sanitize-, и к detector-правилам, поэтому любое правило ниже
        // можно отключить через `safe.input.disabled_rules` или всю группу через `disabled_groups`.
        $filter = $this->makeRuleFilter('safe.input');

        return new InputSafe(
            $filter->filter([
                // ruleId: input.sanitize.invisible_chars; group: input.sanitize; severity: high.
                // Убирает ASCII control, zero-width и bidi/BOM символы до отправки в LLM.
                // Это первый слой защиты от Unicode-smuggling и невидимой обфускации prompt-injection.
                new RemoveInvisibleCharsInputRule(),
                // ruleId: input.sanitize.whitespace; group: input.sanitize; severity: medium.
                // Нормализует пробелы и переносы, чтобы атаки вроде `ignore   previous`
                // не обходили detector-правила простым раздуванием whitespace.
                new NormalizeWhitespaceInputRule(),
                // ruleId: input.sanitize.repeat_chars; group: input.sanitize; severity: medium.
                // Схлопывает длинные повторы символов, которые часто используются как шум
                // для jailbreak/BoN payload и ухудшают качество дальнейшей regex-детекции.
                new CollapseRepeatCharsInputRule(5),
                // ruleId: input.sanitize.max_length; group: input.sanitize; severity: medium.
                // Обрезает слишком длинный вход. Это не security-block, а защита от stuffing
                // и неконтролируемого расхода контекста; лимит настраивается `safe.input.max_length`.
                new MaxLengthInputRule($maxLength),
            ]),
            $filter->filter([
                // ruleId: input.prompt.instruction_override; group: input.prompt_injection; severity: high.
                // Блокирует прямые формулировки "ignore/disregard/forget previous/system instructions".
                // False-positive риск низкий: пользовательский запрос редко легитимно требует игнорировать системные правила.
                new RegexInjectionInputRule(
                    'input.prompt.instruction_override',
                    'Input tries to override system instructions.',
                    '/\b(ignore|disregard|forget)\b.{0,40}\b(previous|system|earlier)\b.{0,40}\b(instructions?|prompt)\b/iu',
                    'input.prompt_injection',
                    RuleSeverityEnum::HIGH,
                    'Low: direct override wording is rarely legitimate user data.'
                ),
                // ruleId: input.prompt.exfiltration; group: input.prompt_exfiltration; severity: high.
                // Ловит прямые просьбы reveal/show/print/dump/leak/expose системный prompt
                // или скрытые/internal instructions. Такие запросы не должны доходить до провайдера LLM.
                new RegexInjectionInputRule(
                    'input.prompt.exfiltration',
                    'Input requests hidden/system prompt disclosure.',
                    '/\b(reveal|show|print|dump|leak|expose)\b.{0,40}\b(system prompt|hidden instructions?|internal instructions?)\b/iu',
                    'input.prompt_exfiltration',
                    RuleSeverityEnum::HIGH,
                    'Low: direct system prompt extraction requests are unsafe.'
                ),
                // ruleId: input.prompt.jailbreak_role_hijack; group: input.jailbreak; severity: high.
                // Блокирует известные jailbreak-маркеры: developer mode, DAN, do anything now,
                // bypass safety, disable guardrails. Это короткие высокосигнальные индикаторы атаки.
                new RegexInjectionInputRule(
                    'input.prompt.jailbreak_role_hijack',
                    'Input contains role-hijack or jailbreak markers.',
                    '/\b(developer mode|dan\b|do anything now|bypass safety|disable guardrails?|jailbreak)\b/iu',
                    'input.jailbreak',
                    RuleSeverityEnum::HIGH,
                    'Low: explicit jailbreak markers should not reach the model.'
                ),
                // ruleId: input.prompt.reset_ru; group: input.prompt_injection; severity: high.
                // Русскоязычный аналог instruction reset: "забудь все инструкции/правила/промпт".
                // Добавлен из opencode-policy как локализованное high-confidence правило.
                new RegexInjectionInputRule(
                    'input.prompt.reset_ru',
                    'Input asks to forget Russian instructions/rules/prompt.',
                    '/забудь\s+(все\s+)?(инструкции|правила|промпт)/iu',
                    'input.prompt_injection',
                    RuleSeverityEnum::HIGH,
                    'Low: ordinary Russian requests rarely ask the model to forget rules.'
                ),
                // ruleId: input.prompt.fake_role_tag; group: input.fake_role_tag; severity: high.
                // Блокирует поддельные role tags `[system]`, `[admin]`, `[developer]`, которыми
                // атакующий пытается внедрить новый privileged-message в обычный user input.
                // При работе над документацией prompt-форматов правило можно отключить по ruleId.
                new RegexInjectionInputRule(
                    'input.prompt.fake_role_tag',
                    'Input contains fake system/admin/developer role tag.',
                    '/\[(system|admin|developer)\]/iu',
                    'input.fake_role_tag',
                    RuleSeverityEnum::HIGH,
                    'Medium: documentation can mention tags; disable for prompt-engineering docs.'
                ),
                // ruleId: input.prompt.new_instructions; group: input.prompt_injection; severity: high.
                // Блокирует явный маркер нового блока инструкций `new instructions:`.
                // Это частый способ разделить payload на "старые правила" и "новую policy".
                new RegexInjectionInputRule(
                    'input.prompt.new_instructions',
                    'Input attempts to inject a new instruction block.',
                    '/\bnew\s+instructions?\s*:/iu',
                    'input.prompt_injection',
                    RuleSeverityEnum::HIGH,
                    'Low: this phrase is a common direct prompt-injection marker.'
                ),
                // ruleId: input.prompt.override_reset; group: input.prompt_injection; severity: high.
                // Расширяет reset/override покрытие: override your/all/previous,
                // disregard your/all/previous, reset to default/factory/original.
                // Это не low-confidence roleplay, а прямое управление поведением модели.
                new RegexInjectionInputRule(
                    'input.prompt.override_reset',
                    'Input tries to override, disregard or reset current behavior.',
                    '/\b(override\s+(your|all|previous)|disregard\s+(your|all|previous)|reset\s+(to|your)\s+(default|factory|original))\b/iu',
                    'input.prompt_injection',
                    RuleSeverityEnum::HIGH,
                    'Low: direct behavior reset requests should be blocked before LLM.'
                ),
                // ruleId: input.prompt.exfiltration_extended; group: input.prompt_exfiltration; severity: high.
                // Расширяет prompt-extraction: reveal/show me your/the system|prompt|instructions.
                // Паттерн намеренно узкий, чтобы не блокировать обычные вопросы о документации.
                new RegexInjectionInputRule(
                    'input.prompt.exfiltration_extended',
                    'Input asks to reveal/show system, prompt or instructions.',
                    '/\b(reveal|show)\s+(me\s+)?(your|the)\s+(system|prompt|instructions)\b/iu',
                    'input.prompt_exfiltration',
                    RuleSeverityEnum::HIGH,
                    'Low: direct extraction requests should not reach the model.'
                ),
                // ruleId: input.obfuscation.decode_exec; group: input.obfuscation; severity: high.
                // Блокирует связку "decode/decrypt ... execute/run/eval" на английском и русском.
                // Такая связка обычно означает попытку спрятать исполняемые инструкции в encoded payload.
                // Для задач аудита payload правило можно отключить по группе `input.obfuscation`.
                new RegexInjectionInputRule(
                    'input.obfuscation.decode_exec',
                    'Input combines decode/decrypt with execute/run/eval.',
                    '/\b(decode|decrypt|декодируй|раскодируй|расшифруй)\b.*\b(execute|run|eval|выполни|запусти|исполни)\b/iu',
                    'input.obfuscation',
                    RuleSeverityEnum::HIGH,
                    'Medium: some security analysis tasks discuss this pattern; disable when auditing payloads.'
                ),
                // ruleId: input.obfuscation.base64_exec_payload; group: input.obfuscation; severity: high.
                // Ищет длинный base64-like фрагмент рядом с run/exec/выполни/запусти.
                // Сам base64 не блокируется: правило срабатывает только рядом с просьбой выполнить payload,
                // чтобы снизить false-positive на логах, тестовых данных и harmless encoded examples.
                new RegexInjectionInputRule(
                    'input.obfuscation.base64_exec_payload',
                    'Input contains long base64-like payload near run/exec request.',
                    '/((выполни|запусти|run|exec).*[A-Za-z0-9+\/]{40,}={0,2}|[A-Za-z0-9+\/]{40,}={0,2}.*(выполни|запусти|run|exec))/iu',
                    'input.obfuscation',
                    RuleSeverityEnum::HIGH,
                    'Medium: logs and encoded samples can be legitimate in security work.'
                ),
                // ruleId: input.obfuscation.base64_known_secrets; group: input.obfuscation; severity: high.
                // Блокирует известные base64-литералы для `import os`, `os.environ`,
                // `/proc/self/environ`, `/run/secrets`. Это high-confidence индикаторы попытки
                // получить окружение или секреты через скрытую инструкцию.
                new RegexInjectionInputRule(
                    'input.obfuscation.base64_known_secrets',
                    'Input contains known base64 literals for OS/env/secret access.',
                    '/(aW1wb3J0IG9z|b3MuZW52aXJvbg|L3Byb2Mvc2VsZi9lbnZpcm9u|L3J1bi9zZWNyZXRz)/u',
                    'input.obfuscation',
                    RuleSeverityEnum::HIGH,
                    'Low: these literals encode high-risk secret/environment access patterns.'
                ),
                // ruleId: input.low_confidence.roleplay; group: input.low_confidence; severity: low.
                // Ловит `pretend you are`, `act as`, `you are now`, но группа отключена по умолчанию
                // в `makeRuleFilter()`: эти фразы часто нужны в нормальных задачах. Правило оставлено
                // как opt-in для более строгих окружений или будущих составных detector-политик.
                new RegexInjectionInputRule(
                    'input.low_confidence.roleplay',
                    'Input contains low-confidence roleplay override wording.',
                    '/\b(pretend\s+(you\s+)?(are|to\s+be)|act\s+as\s+(if|a|an)|you\s+are\s+now\s+(a|an|in))\b/iu',
                    'input.low_confidence',
                    RuleSeverityEnum::LOW,
                    'High: roleplay phrasing is common in legitimate prompts; group is disabled by default.'
                ),
                // ruleId: input.obfuscation.typoglycemia; group: input.obfuscation; severity: medium.
                // Ловит перестановку внутренних букв в опасных словах (`ignroe`, `bpyass` и т.п.).
                // Правило полезно против простой текстовой обфускации, но имеет medium severity из-за
                // возможных случайных опечаток в одиночных словах.
                new TypoglycemiaInputRule(),
            ])
        );
    }

    /**
     * Собирает дефолтный пайплайн OutputSafe.
     *
     * @return OutputSafe Сервис выходной защиты с учётом `safe.output.*`.
     */
    private function buildDefaultOutputSafe(): OutputSafe
    {
        // App-level флаг выключает только состав output-правил. Agent-level `safeOutput=false`
        // дополнительно позволяет конкретному агенту не подключать output sanitize-декоратор.
        if (!OptionsHelper::toBool($this->get('safe.output.enabled', true))) {
            return new OutputSafe([]);
        }

        // Все output-правила ниже работают в гибридном режиме: они редактируют найденный фрагмент,
        // возвращают DTO нарушения, а `SafeAIProviderDecorator` логирует событие `llm.output.redacted`.
        $filter = $this->makeRuleFilter('safe.output');

        return new OutputSafe(
            $filter->filter([
                // ruleId: output.prompt.system_prompt_leak; group: output.prompt_leakage; severity: high.
                // Редактирует фразы, похожие на раскрытие system prompt / hidden/internal instructions.
                // Замена сохраняет ответ, но убирает потенциально чувствительный фрагмент.
                new RegexLeakOutputRule(
                    'output.prompt.system_prompt_leak',
                    'Output may disclose hidden/system prompt.',
                    '/\b(system prompt|hidden instructions?|internal instructions?)\b.{0,200}/iu',
                    '[REDACTED_SYSTEM_PROMPT_FRAGMENT]',
                    'output.prompt_leakage',
                    RuleSeverityEnum::HIGH,
                    'Medium: explanatory text about prompts can be redacted.'
                ),
                // ruleId: output.secret.api_key; group: output.secrets; severity: high.
                // Редактирует token-like строки: `sk-...`, `api_key=...`, `Bearer ...`.
                // Паттерн намеренно требует минимальную длину значения, чтобы не скрывать короткие примеры.
                new RegexLeakOutputRule(
                    'output.secret.api_key',
                    'Output may disclose API/token secret.',
                    '/\b(sk-[a-z0-9]{16,}|api[_-]?key\s*[:=]\s*[a-z0-9_\-]{10,}|bearer\s+[a-z0-9\._\-]{20,})\b/iu',
                    '[REDACTED_SECRET]',
                    'output.secrets',
                    RuleSeverityEnum::HIGH,
                    'Low: key/token-like strings should not be returned to users.'
                ),
                // ruleId: output.secret.env_assignment; group: output.secrets; severity: high.
                // Редактирует env-like присваивания для KEY/TOKEN/SECRET/PASSWORD/PASS/CREDENTIAL.
                // Это защищает от ситуаций, когда LLM цитирует `.env` или shell output с секретами.
                // False-positive возможен на учебных fake-примерах, поэтому правило отключается по ruleId.
                new RegexLeakOutputRule(
                    'output.secret.env_assignment',
                    'Output may disclose env-like secret assignment.',
                    '/\b(KEY|TOKEN|SECRET|PASSWORD|PASS|CREDENTIAL)[A-Z0-9_]*\s*=\s*[^\s"\']{6,}/iu',
                    '[REDACTED_SECRET]',
                    'output.secrets',
                    RuleSeverityEnum::HIGH,
                    'Medium: examples can use fake KEY=VALUE; tests should use placeholders.'
                ),
                // ruleId: output.secret.sensitive_paths; group: output.secrets; severity: high.
                // Редактирует пути к источникам секретов: `/proc/self/environ`, `/run/secrets`, `.env`.
                // Даже если значение секрета не раскрыто, подсказка к такому пути может направить
                // следующий tool-вызов к утечке.
                new RegexLeakOutputRule(
                    'output.secret.sensitive_paths',
                    'Output may disclose sensitive environment/secret path.',
                    '/(\/proc\/self\/environ|\/run\/secrets|\.env\b)/iu',
                    '[REDACTED_SENSITIVE_PATH]',
                    'output.secrets',
                    RuleSeverityEnum::HIGH,
                    'Low: these paths should not be surfaced in model answers.'
                ),
                // ruleId: output.prompt.policy_leak; group: output.prompt_leakage; severity: high.
                // Редактирует признаки раскрытия developer/system instructions, hidden/internal policy.
                // Правило дополняет system_prompt_leak и покрывает современные multi-policy LLM setups.
                new RegexLeakOutputRule(
                    'output.prompt.policy_leak',
                    'Output may disclose internal policy or developer instructions.',
                    '/\b(system instructions?|developer instructions?|hidden policy|internal policy)\b.{0,200}/iu',
                    '[REDACTED_INTERNAL_POLICY_FRAGMENT]',
                    'output.prompt_leakage',
                    RuleSeverityEnum::HIGH,
                    'Medium: documentation about policies can be redacted.'
                ),
            ])
        );
    }

    /**
     * Возвращает дефолтные blockedPatterns для `BashTool` с учётом `safe.tools.bash.*`.
     *
     * @return list<string> Regex-паттерны, которые должны быть добавлены к blockedPatterns.
     */
    public function getBashToolBlockedPatterns(): array
    {
        if (!OptionsHelper::toBool($this->get('safe.tools.bash.enabled', true))) {
            return [];
        }

        $rules = $this->makeRuleFilter('safe.tools.bash')->filter(DefaultBashToolPolicy::rules());

        return DefaultBashToolPolicy::patterns($rules);
    }

    /**
     * Создаёт фильтр правил из конфигурационного префикса.
     *
     * @param string $prefix Префикс конфигурации, например `safe.input`.
     *
     * @return RuleFilter Фильтр с disabled_rules и disabled_groups.
     */
    private function makeRuleFilter(string $prefix): RuleFilter
    {
        $defaultDisabledGroups = $prefix === 'safe.input' ? ['input.low_confidence'] : [];

        return new RuleFilter(
            $this->getStringListConfig($prefix . '.disabled_rules'),
            $this->getStringListConfig($prefix . '.disabled_groups', $defaultDisabledGroups)
        );
    }

    /**
     * Читает список строк из config.jsonc и отбрасывает пустые/нестроковые значения.
     *
     * @param string       $key     Ключ конфигурации с массивом строк.
     * @param list<string> $default Значение по умолчанию.
     *
     * @return list<string> Нормализованный список строк.
     */
    private function getStringListConfig(string $key, array $default = []): array
    {
        $raw = $this->get($key, $default);
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $result[] = $value;
            }
        }

        return array_values(array_unique($result));
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
