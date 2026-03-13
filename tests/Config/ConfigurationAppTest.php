<?php

declare(strict_types=1);

namespace Tests\Config;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Тесты для {@see ConfigurationApp}.
 *
 * ConfigurationApp — синглтон конфигурации консольного приложения.
 * Загружает настройки из файла config.jsonc, предоставляет доступ
 * к DirPriority, SessionKey, а также к Producer'ам (AgentProducer,
 * TodoListProducer, SkillProducer).
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\config\ConfigurationApp}
 */
class ConfigurationAppTest extends TestCase
{
    /** @var string Временная директория для тестовых конфигурационных файлов. */
    private string $tmpDir;

    /**
     * Создаёт временные директории и сбрасывает синглтон.
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_appconf_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);

        $this->resetSingleton();
    }

    /**
     * Сбрасывает синглтон и удаляет временные файлы.
     */
    protected function tearDown(): void
    {
        $this->resetSingleton();
        $this->removeDir($this->tmpDir);
    }

    /**
     * Сбрасывает приватное статическое свойство $instance через Reflection.
     */
    private function resetSingleton(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);
    }

    /**
     * Рекурсивное удаление директории.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ══════════════════════════════════════════════════════════════
    //  init / getInstance — жизненный цикл синглтона
    // ══════════════════════════════════════════════════════════════

    /**
     * Обращение к getInstance() без предварительного init() —
     * RuntimeException.
     */
    public function testGetInstanceWithoutInitThrows(): void
    {
        $this->expectException(RuntimeException::class);
        ConfigurationApp::getInstance();
    }

    /**
     * После init() синглтон доступен через getInstance().
     */
    public function testInitAndGetInstance(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $instance = ConfigurationApp::getInstance();
        $this->assertInstanceOf(ConfigurationApp::class, $instance);
    }

    /**
     * Повторный вызов init() игнорируется — возвращается тот же экземпляр.
     */
    public function testDoubleInitIgnored(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $first = ConfigurationApp::getInstance();

        ConfigurationApp::init($dp);
        $second = ConfigurationApp::getInstance();

        $this->assertSame($first, $second);
    }

    // ══════════════════════════════════════════════════════════════
    //  Загрузка конфигурации
    // ══════════════════════════════════════════════════════════════

    /**
     * Файл конфигурации не найден — используется пустой массив (defaults).
     */
    public function testLoadWithNoConfigFileUsesDefaults(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp, 'config.jsonc');
        $app = ConfigurationApp::getInstance();

        $this->assertSame([], $app->getAll());
    }

    /**
     * Валидный JSONC-файл конфигурации — его содержимое загружается.
     */
    public function testLoadValidJsonc(): void
    {
        file_put_contents($this->tmpDir . '/config.jsonc', '{"appName": "test"}');
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp, 'config.jsonc');

        $this->assertSame('test', ConfigurationApp::getInstance()->get('appName'));
    }

    /**
     * JSONC с однострочными и инлайновыми комментариями — парсится корректно.
     */
    public function testLoadJsoncWithComments(): void
    {
        $json = "{\n  // comment\n  \"key\": \"value\" // inline\n}";
        file_put_contents($this->tmpDir . '/config.jsonc', $json);
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp, 'config.jsonc');

        $this->assertSame('value', ConfigurationApp::getInstance()->get('key'));
    }

    /**
     * Невалидный JSON в файле конфигурации — RuntimeException.
     */
    public function testLoadInvalidJsonThrows(): void
    {
        file_put_contents($this->tmpDir . '/bad.jsonc', '{invalid}');
        $dp = new DirPriority([$this->tmpDir]);

        $this->expectException(RuntimeException::class);
        ConfigurationApp::init($dp, 'bad.jsonc');
    }

    /**
     * JSON-файл декодируется не в массив (скалярное значение) — RuntimeException.
     */
    public function testLoadNonArrayJsonThrows(): void
    {
        file_put_contents($this->tmpDir . '/scalar.jsonc', '"just a string"');
        $dp = new DirPriority([$this->tmpDir]);

        $this->expectException(RuntimeException::class);
        ConfigurationApp::init($dp, 'scalar.jsonc');
    }

    // ══════════════════════════════════════════════════════════════
    //  get — доступ к настройкам
    // ══════════════════════════════════════════════════════════════

    /**
     * Существующий ключ — возвращается соответствующее значение.
     */
    public function testGetExistingKey(): void
    {
        file_put_contents($this->tmpDir . '/config.jsonc', '{"key": "value"}');
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $this->assertSame('value', ConfigurationApp::getInstance()->get('key'));
    }

    /**
     * Несуществующий ключ — возвращается значение по умолчанию.
     */
    public function testGetMissingKeyReturnsDefault(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $this->assertSame('fallback', ConfigurationApp::getInstance()->get('missing', 'fallback'));
    }

    /**
     * Несуществующий ключ без указания default — возвращается null.
     */
    public function testGetMissingKeyReturnsNullDefault(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $this->assertNull(ConfigurationApp::getInstance()->get('missing'));
    }

    // ══════════════════════════════════════════════════════════════
    //  getDirPriority
    // ══════════════════════════════════════════════════════════════

    /**
     * getDirPriority() возвращает тот же DirPriority, что был передан в init().
     */
    public function testGetDirPriority(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $this->assertSame($dp, ConfigurationApp::getInstance()->getDirPriority());
    }

    // ══════════════════════════════════════════════════════════════
    //  getSessionDirName / getSessionDir
    // ══════════════════════════════════════════════════════════════

    /**
     * Имя директории сессий — константная строка «.sessions».
     */
    public function testGetSessionDirName(): void
    {
        $this->assertSame('.sessions', ConfigurationApp::getSessionDirName());
    }

    /**
     * getSessionDir() возвращает полный путь к директории сессий.
     */
    public function testGetSessionDir(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $sessionDir = ConfigurationApp::getInstance()->getSessionDir();
        $this->assertSame($this->tmpDir . '/.sessions', $sessionDir);
    }

    // ══════════════════════════════════════════════════════════════
    //  logDir — директория логов
    // ══════════════════════════════════════════════════════════════

    /**
     * Имя директории логов — константная строка «.logs».
     */
    public function testGetLogDirName(): void
    {
        $this->assertSame('.logs', ConfigurationApp::getLogDirName());
    }

    /**
     * getLogDir() возвращает полный путь к директории логов.
     */
    public function testGetLogDir(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $logDir = ConfigurationApp::getInstance()->getLogDir();
        $this->assertSame($this->tmpDir . '/.logs', $logDir);
    }

    // ══════════════════════════════════════════════════════════════
    //  sessionKey — ключ сессии
    // ══════════════════════════════════════════════════════════════

    /**
     * getSessionKey() генерирует ключ лениво при первом вызове
     * и при повторных вызовах возвращает тот же.
     */
    public function testGetSessionKeyGeneratesLazily(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $app = ConfigurationApp::getInstance();

        $key = $app->getSessionKey();
        $this->assertNotEmpty($key);
        $this->assertSame($key, $app->getSessionKey());
    }

    /**
     * setSessionKey() заменяет ключ на явно указанный.
     */
    public function testSetSessionKey(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $app = ConfigurationApp::getInstance();

        $app->setSessionKey('custom-key');
        $this->assertSame('custom-key', $app->getSessionKey());
    }

    /**
     * При инициализации с явным userId ключ сессии содержит суффикс -userId,
     * а getUserId() возвращает это же значение.
     */
    public function testGetSessionKeyIncludesUserIdFromInit(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp, 'config.jsonc', 123);
        $app = ConfigurationApp::getInstance();

        $key = $app->getSessionKey();
        $this->assertMatchesRegularExpression('/^\\d{8}-\\d{6}-\\d+-123$/', $key);
        $this->assertSame(123, $app->getUserId());
    }

    /**
     * Если userId не передан в init(), но указан в config.jsonc,
     * он подхватывается конструктором и влияет на sessionKey и getUserId().
     */
    public function testGetSessionKeyAndUserIdFromConfig(): void
    {
        file_put_contents($this->tmpDir . '/config.jsonc', '{"userId": 777}');
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp, 'config.jsonc');
        $app = ConfigurationApp::getInstance();

        $this->assertSame(777, $app->getUserId());
        $key = $app->getSessionKey();
        $this->assertMatchesRegularExpression('/^\\d{8}-\\d{6}-\\d+-777$/', $key);
    }

    // ══════════════════════════════════════════════════════════════
    //  Producers — ленивое создание фабрик
    // ══════════════════════════════════════════════════════════════

    /**
     * getAgentProducer() возвращает экземпляр AgentProducer.
     */
    public function testGetAgentProducer(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $producer = ConfigurationApp::getInstance()->getAgentProducer();
        $this->assertInstanceOf(\app\modules\neuron\classes\producers\AgentProducer::class, $producer);
    }

    /**
     * Повторный вызов возвращает тот же экземпляр (кеширование).
     */
    public function testGetAgentProducerCached(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $app = ConfigurationApp::getInstance();
        $this->assertSame($app->getAgentProducer(), $app->getAgentProducer());
    }

    /**
     * getTodoListProducer() возвращает экземпляр TodoListProducer.
     */
    public function testGetTodoListProducer(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $producer = ConfigurationApp::getInstance()->getTodoListProducer();
        $this->assertInstanceOf(\app\modules\neuron\classes\producers\TodoListProducer::class, $producer);
    }

    /**
     * getSkillProducer() возвращает экземпляр SkillProducer.
     */
    public function testGetSkillProducer(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $producer = ConfigurationApp::getInstance()->getSkillProducer();
        $this->assertInstanceOf(\app\modules\neuron\classes\producers\SkillProducer::class, $producer);
    }

    // ══════════════════════════════════════════════════════════════
    //  logContext — контекст логирования
    // ══════════════════════════════════════════════════════════════

    /**
     * getLogContext() возвращает массив с текущим sessionKey.
     */
    public function testGetLogContextReturnsCurrentSessionKey(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $app = ConfigurationApp::getInstance();

        $sessionKey = $app->getSessionKey();
        $context = $app->getLogContext();

        $this->assertSame(['session' => $sessionKey], $context);
    }

    // ══════════════════════════════════════════════════════════════
    //  sessionExists — проверка наличия файла сессии
    // ══════════════════════════════════════════════════════════════

    /**
     * Файл сессии не существует — false.
     */
    public function testSessionExistsFalse(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $this->assertFalse(ConfigurationApp::getInstance()->sessionExists('key', 'agent'));
    }

    /**
     * Файл сессии существует (neuron_key-agent.chat) — true.
     */
    public function testSessionExistsTrue(): void
    {
        file_put_contents($this->tmpDir . '/.sessions/neuron_key-agent.chat', 'data');
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $this->assertTrue(ConfigurationApp::getInstance()->sessionExists('key', 'agent'));
    }

    /**
     * Пустое имя агента заменяется на 'unknown' в ключе файла.
     */
    public function testSessionExistsUnknownAgent(): void
    {
        file_put_contents($this->tmpDir . '/.sessions/neuron_key-unknown.chat', 'data');
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $this->assertTrue(ConfigurationApp::getInstance()->sessionExists('key', ''));
    }

    // ══════════════════════════════════════════════════════════════
    //  getStoreDirName / getStoreDir — хранилище чекпоинтов
    // ══════════════════════════════════════════════════════════════

    /**
     * getStoreDirName() возвращает строку .store.
     */
    public function testGetStoreDirName(): void
    {
        $this->assertSame('.store', ConfigurationApp::getStoreDirName());
    }

    /**
     * getStoreDir() возвращает путь к .store, если директория есть в DirPriority.
     */
    public function testGetStoreDir(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
        $storeDir = ConfigurationApp::getInstance()->getStoreDir();
        $this->assertSame($this->tmpDir . '/.store', $storeDir);
    }

    /**
     * getStoreDir() бросает RuntimeException, если .store не найдена.
     */
    public function testGetStoreDirThrowsWhenMissing(): void
    {
        $dirWithoutStore = sys_get_temp_dir() . '/neuronapp_nostore_' . uniqid();
        mkdir($dirWithoutStore, 0777, true);
        $dp = new DirPriority([$dirWithoutStore]);
        ConfigurationApp::init($dp);
        try {
            ConfigurationApp::getInstance()->getStoreDir();
            $this->fail('Ожидалось RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('.store', $e->getMessage());
        } finally {
            rmdir($dirWithoutStore);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  isValidSessionKey — валидация ключа сессии
    // ══════════════════════════════════════════════════════════════

    /**
     * isValidSessionKey() принимает ключ, сгенерированный buildSessionKey().
     */
    public function testIsValidSessionKeyAcceptsBaseFormat(): void
    {
        $key = ConfigurationApp::buildSessionKey();
        $this->assertTrue(ConfigurationApp::isValidSessionKey($key));
    }

    /**
     * Пустая строка не является валидным ключом сессии.
     */
    public function testIsValidSessionKeyRejectsEmptyString(): void
    {
        $this->assertFalse(ConfigurationApp::isValidSessionKey(''));
    }

    /**
     * Ключ с суффиксом userId (как у getSessionKey()) не проходит валидацию
     * базового формата buildSessionKey().
     */
    public function testIsValidSessionKeyRejectsKeyWithUserIdSuffix(): void
    {
        $base = ConfigurationApp::buildSessionKey();
        $this->assertFalse(ConfigurationApp::isValidSessionKey($base . '-123'));
    }

    /**
     * Ключ с неверным форматом (лишние/некорректные символы) отвергается.
     */
    public function testIsValidSessionKeyRejectsBadFormat(): void
    {
        $this->assertFalse(ConfigurationApp::isValidSessionKey('2024-0101-000000-123456'));
        $this->assertFalse(ConfigurationApp::isValidSessionKey('abcdefgh-ijklmn-opqr'));
    }
}
