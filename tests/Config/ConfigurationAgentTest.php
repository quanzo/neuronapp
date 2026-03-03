<?php

declare(strict_types=1);

namespace Tests\Config;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\neuron\providers\EchoProvider;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Providers\AIProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see ConfigurationAgent}.
 *
 * ConfigurationAgent — конфигурация агента для работы с LLM через NeuronAI.
 * Хранит провайдер, инструкции, инструменты, историю чата, настройки RAG и др.
 *
 * Основные возможности:
 *  - makeFromArray() — создание из ассоциативного массива;
 *  - makeFromFile() — создание из PHP- или JSONC-файла;
 *  - cloneForSession() — клонирование со сбросом кеша агента;
 *  - getProvider(), getInstructions(), getTools() — доступ к конфигурации;
 *  - getChatHistory() / setChatHistory() / resetChatHistory() — управление историей;
 *  - getAgent() — ленивое создание агента.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\config\ConfigurationAgent}
 */
class ConfigurationAgentTest extends TestCase
{
    /** @var string Временная директория для тестовых файлов. */
    private string $tmpDir;

    /**
     * Создаёт временные директории и инициализирует ConfigurationApp-синглтон.
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_cfg_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);

        $this->resetConfigurationAppSingleton();

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp, 'config.jsonc');
    }

    /**
     * Сбрасывает синглтон и удаляет временные файлы.
     */
    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        $this->removeDir($this->tmpDir);
    }

    /**
     * Сбрасывает приватное статическое свойство $instance через Reflection,
     * чтобы каждый тест начинался с чистого состояния.
     */
    private function resetConfigurationAppSingleton(): void
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
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ══════════════════════════════════════════════════════════════
    //  makeFromArray
    // ══════════════════════════════════════════════════════════════

    /**
     * Пустой массив настроек — возвращается null.
     */
    public function testMakeFromArrayEmptyReturnsNull(): void
    {
        $this->assertNull(ConfigurationAgent::makeFromArray([]));
    }

    /**
     * Минимальный набор настроек — объект создаётся с правильными значениями.
     */
    public function testMakeFromArrayBasic(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
            'contextWindow' => 10000,
            'instructions' => 'Be helpful',
        ], 'test-session');

        $this->assertInstanceOf(ConfigurationAgent::class, $cfg);
        $this->assertFalse($cfg->enableChatHistory);
        $this->assertSame(10000, $cfg->contextWindow);
        $this->assertSame('Be helpful', $cfg->instructions);
        $this->assertSame('test-session', $cfg->getSessionKey());
    }

    /**
     * Все поддерживаемые ключи массива обрабатываются и присваиваются
     * соответствующим свойствам конфигурации.
     */
    public function testMakeFromArrayAllFields(): void
    {
        $provider = new EchoProvider();
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
            'contextWindow' => 20000,
            'history_id' => 5,
            'reponseStructClass' => 'SomeClass',
            'provider' => $provider,
            'instructions' => 'Test',
            'tools' => [],
            'toolMaxTries' => 3,
            'mcp' => [],
            'embeddingProvider' => null,
            'embeddingChunkSize' => 500,
            'vectorStore' => null,
        ], 'session-key');

        $this->assertSame(20000, $cfg->contextWindow);
        $this->assertSame(5, $cfg->history_id);
        $this->assertSame('SomeClass', $cfg->reponseStructClass);
        $this->assertSame(3, $cfg->toolMaxTries);
        $this->assertSame(500, $cfg->embeddingChunkSize);
    }

    /**
     * history_id = null явно передаётся — свойство устанавливается в null.
     */
    public function testMakeFromArrayNullHistoryId(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'history_id' => null,
        ], 'session');

        $this->assertNull($cfg->history_id);
    }

    /**
     * Если sessionKey не передан (null) — генерируется автоматически
     * в формате «YYYYMMDD-HHMMSS-μs».
     */
    public function testMakeFromArrayGeneratesSessionKeyWhenNull(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
        ]);

        $this->assertNotNull($cfg->getSessionKey());
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-\d+$/', $cfg->getSessionKey());
    }

    // ══════════════════════════════════════════════════════════════
    //  makeFromFile — создание конфигурации из файла
    // ══════════════════════════════════════════════════════════════

    /**
     * PHP-файл, возвращающий массив — конфигурация создаётся корректно.
     */
    public function testMakeFromFilePhp(): void
    {
        $filePath = $this->tmpDir . '/agent.php';
        file_put_contents($filePath, '<?php return ["enableChatHistory" => false, "contextWindow" => 5000];');

        $cfg = ConfigurationAgent::makeFromFile($filePath, 'test-session');
        $this->assertInstanceOf(ConfigurationAgent::class, $cfg);
        $this->assertFalse($cfg->enableChatHistory);
        $this->assertSame(5000, $cfg->contextWindow);
    }

    /**
     * JSONC-файл (JSON с комментариями) корректно парсится.
     */
    public function testMakeFromFileJsonc(): void
    {
        $filePath = $this->tmpDir . '/agent.jsonc';
        file_put_contents($filePath, '{"enableChatHistory": false, "contextWindow": 7000}');

        $cfg = ConfigurationAgent::makeFromFile($filePath, 'test-session');
        $this->assertInstanceOf(ConfigurationAgent::class, $cfg);
        $this->assertSame(7000, $cfg->contextWindow);
    }

    /**
     * Обычный JSON-файл (расширение .json) — тоже поддерживается.
     */
    public function testMakeFromFileJson(): void
    {
        $filePath = $this->tmpDir . '/agent.json';
        file_put_contents($filePath, '{"contextWindow": 8000}');

        $cfg = ConfigurationAgent::makeFromFile($filePath, 'test-session');
        $this->assertInstanceOf(ConfigurationAgent::class, $cfg);
        $this->assertSame(8000, $cfg->contextWindow);
    }

    /**
     * Пустой путь — null (граничный случай).
     */
    public function testMakeFromFileEmptyPath(): void
    {
        $this->assertNull(ConfigurationAgent::makeFromFile(''));
    }

    /**
     * Несуществующий файл — null.
     */
    public function testMakeFromFileNonExistent(): void
    {
        $this->assertNull(ConfigurationAgent::makeFromFile('/nonexistent/path.php'));
    }

    /**
     * Неподдерживаемое расширение (yaml) — null.
     */
    public function testMakeFromFileUnsupportedExtension(): void
    {
        $filePath = $this->tmpDir . '/agent.yaml';
        file_put_contents($filePath, 'key: value');

        $this->assertNull(ConfigurationAgent::makeFromFile($filePath));
    }

    /**
     * Невалидный JSON в файле — null.
     */
    public function testMakeFromFileInvalidJson(): void
    {
        $filePath = $this->tmpDir . '/bad.jsonc';
        file_put_contents($filePath, '{invalid json}');

        $this->assertNull(ConfigurationAgent::makeFromFile($filePath));
    }

    /**
     * PHP-файл возвращает не массив (строку) — null.
     */
    public function testMakeFromFilePhpReturnsNonArray(): void
    {
        $filePath = $this->tmpDir . '/bad.php';
        file_put_contents($filePath, '<?php return "not an array";');

        $this->assertNull(ConfigurationAgent::makeFromFile($filePath));
    }

    /**
     * JSONC с однострочными и инлайновыми комментариями —
     * комментарии удаляются, JSON парсится.
     */
    public function testMakeFromFileJsoncWithComments(): void
    {
        $filePath = $this->tmpDir . '/commented.jsonc';
        $contents = <<<'JSONC'
{
    // this is a comment
    "enableChatHistory": false,
    "contextWindow": 9000 // inline
}
JSONC;
        file_put_contents($filePath, $contents);

        $cfg = ConfigurationAgent::makeFromFile($filePath, 'session');
        $this->assertInstanceOf(ConfigurationAgent::class, $cfg);
        $this->assertSame(9000, $cfg->contextWindow);
    }

    // ══════════════════════════════════════════════════════════════
    //  cloneForSession — клонирование для сессии
    // ══════════════════════════════════════════════════════════════

    /**
     * cloneForSession() создаёт новый объект со сброшенным кешем агента
     * (свойство _agent = null), чтобы можно было добавить инструменты.
     */
    public function testCloneForSessionResetsAgent(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
            'provider' => new EchoProvider(),
        ], 'session');

        $cfg->getAgent();

        $clone = $cfg->cloneForSession();
        $this->assertNotSame($cfg, $clone);

        $ref = new \ReflectionClass($clone);
        $agentProp = $ref->getProperty('_agent');
        $this->assertNull($agentProp->getValue($clone));
    }

    /**
     * Клон сохраняет все публичные свойства оригинала.
     */
    public function testCloneForSessionPreservesConfig(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
            'contextWindow' => 12345,
            'instructions' => 'Keep it',
        ], 'session');

        $clone = $cfg->cloneForSession();
        $this->assertSame(12345, $clone->contextWindow);
        $this->assertSame('Keep it', $clone->instructions);
    }

    // ══════════════════════════════════════════════════════════════
    //  getProvider — получение LLM-провайдера
    // ══════════════════════════════════════════════════════════════

    /**
     * Провайдер задан экземпляром AIProviderInterface — возвращается как есть.
     */
    public function testGetProviderFromInstance(): void
    {
        $provider = new EchoProvider();
        $cfg = ConfigurationAgent::makeFromArray([
            'provider' => $provider,
        ], 'session');

        $this->assertSame($provider, $cfg->getProvider());
    }

    /**
     * Провайдер задан через callable (замыкание) — вызывается при первом обращении.
     */
    public function testGetProviderFromCallable(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'provider' => fn() => new EchoProvider(),
        ], 'session');

        $result = $cfg->getProvider();
        $this->assertInstanceOf(EchoProvider::class, $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  getInstructions — системные инструкции
    // ══════════════════════════════════════════════════════════════

    /**
     * Строка инструкций возвращается как есть.
     */
    public function testGetInstructionsString(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'instructions' => 'Be helpful',
        ], 'session');

        $this->assertSame('Be helpful', $cfg->getInstructions());
    }

    /**
     * Callable-инструкции вызываются и возвращают строку.
     */
    public function testGetInstructionsCallable(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'instructions' => fn() => 'Dynamic instructions',
        ], 'session');

        $this->assertSame('Dynamic instructions', $cfg->getInstructions());
    }

    /**
     * По умолчанию инструкции — пустая строка.
     */
    public function testGetInstructionsEmpty(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
        ], 'session');

        $this->assertSame('', $cfg->getInstructions());
    }

    // ══════════════════════════════════════════════════════════════
    //  getTools — инструменты LLM
    // ══════════════════════════════════════════════════════════════

    /**
     * Пустой массив инструментов — возвращается пустой массив.
     */
    public function testGetToolsEmpty(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'tools' => [],
        ], 'session');

        $this->assertSame([], $cfg->getTools());
    }

    // ══════════════════════════════════════════════════════════════
    //  sessionKey — ключ сессии
    // ══════════════════════════════════════════════════════════════

    /**
     * setSessionKey() сбрасывает кеш истории чата (чтобы при следующем
     * обращении создалась новая история с новым ключом).
     */
    public function testSetSessionKeyResetsChatHistory(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
        ], 'session-1');

        $cfg->getChatHistory();
        $cfg->setSessionKey('session-2');

        $ref = new \ReflectionClass($cfg);
        $prop = $ref->getProperty('_chatHistory');
        $this->assertNull($prop->getValue($cfg));
    }

    /**
     * getSessionKey() возвращает ключ, установленный при создании.
     */
    public function testGetSessionKey(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
        ], 'my-session');

        $this->assertSame('my-session', $cfg->getSessionKey());
    }

    // ══════════════════════════════════════════════════════════════
    //  getChatHistory — история чата
    // ══════════════════════════════════════════════════════════════

    /**
     * При enableChatHistory = false используется InMemoryChatHistory
     * (не требует файловой системы).
     */
    public function testGetChatHistoryInMemoryWhenDisabled(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
        ], 'session');

        $history = $cfg->getChatHistory();
        $this->assertInstanceOf(InMemoryChatHistory::class, $history);
    }

    /**
     * Повторный вызов getChatHistory() возвращает тот же экземпляр (кеширование).
     */
    public function testGetChatHistoryCached(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
        ], 'session');

        $first = $cfg->getChatHistory();
        $second = $cfg->getChatHistory();
        $this->assertSame($first, $second);
    }

    /**
     * setChatHistory() заменяет кешированный объект истории на переданный.
     */
    public function testSetChatHistory(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
        ], 'session');

        $custom = new InMemoryChatHistory(1000);
        $cfg->setChatHistory($custom);

        $this->assertSame($custom, $cfg->getChatHistory());
    }

    /**
     * resetChatHistory() обнуляет кеш — при следующем getChatHistory()
     * будет создан новый экземпляр.
     */
    public function testResetChatHistory(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
        ], 'session');

        $cfg->getChatHistory();
        $cfg->resetChatHistory();

        $ref = new \ReflectionClass($cfg);
        $prop = $ref->getProperty('_chatHistory');
        $this->assertNull($prop->getValue($cfg));
    }

    // ══════════════════════════════════════════════════════════════
    //  getAgent — создание агента
    // ══════════════════════════════════════════════════════════════

    /**
     * getAgent() возвращает объект, реализующий AgentInterface.
     */
    public function testGetAgentReturnsAgentInterface(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
            'provider' => new EchoProvider(),
        ], 'session');

        $agent = $cfg->getAgent();
        $this->assertInstanceOf(\NeuronAI\Agent\AgentInterface::class, $agent);
    }

    /**
     * Повторный вызов getAgent() возвращает тот же экземпляр (кеширование).
     */
    public function testGetAgentCached(): void
    {
        $cfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
            'provider' => new EchoProvider(),
        ], 'session');

        $first = $cfg->getAgent();
        $second = $cfg->getAgent();
        $this->assertSame($first, $second);
    }

    // ══════════════════════════════════════════════════════════════
    //  buildSessionKey — генерация ключа сессии
    // ══════════════════════════════════════════════════════════════

    /**
     * Формат ключа: YYYYMMDD-HHMMSS-микросекунды.
     */
    public function testBuildSessionKeyFormat(): void
    {
        $key = ConfigurationApp::buildSessionKey();
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-\d+$/', $key);
    }

    /**
     * Два ключа, сгенерированные с паузой, не совпадают (уникальность).
     */
    public function testBuildSessionKeyUnique(): void
    {
        $key1 = ConfigurationApp::buildSessionKey();
        usleep(1000);
        $key2 = ConfigurationApp::buildSessionKey();
        $this->assertNotSame($key1, $key2);
    }
}
