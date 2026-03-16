<?php

declare(strict_types=1);

namespace Tests\Producers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\producers\AgentProducer;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see AgentProducer}.
 *
 * AgentProducer — фабрика конфигураций агентов (ConfigurationAgent) по имени.
 * Ищет файлы в поддиректории «agents/» через DirPriority.
 * Приоритет форматов: сначала PHP (.php), затем JSONC (.jsonc).
 * Результат кешируется по имени агента.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\producers\AgentProducer}
 */
class AgentProducerTest extends TestCase
{
    /** @var string Временная директория с подкаталогом agents/. */
    private string $tmpDir;

    /**
     * Создаёт временные директории и инициализирует ConfigurationApp-синглтон
     * (нужен для корректной работы makeFromFile внутри AgentProducer).
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_agentprod_' . uniqid();
        mkdir($this->tmpDir . '/agents', 0777, true);
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
     * Сбрасывает приватное статическое свойство $instance через Reflection.
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
    //  Статические метаданные
    // ══════════════════════════════════════════════════════════════

    /**
     * Имя поддиректории хранения — «agents».
     */
    public function testGetStorageDirName(): void
    {
        $this->assertSame('agents', AgentProducer::getStorageDirName());
    }

    /**
     * Имя агента по умолчанию — «default».
     */
    public function testGetDefaultAgentName(): void
    {
        $this->assertSame('default', AgentProducer::getDefaultAgentName());
    }

    // ══════════════════════════════════════════════════════════════
    //  Ключ сессии
    // ══════════════════════════════════════════════════════════════

    /**
     * getSessionKey() проксирует базовый ключ сессии из ConfigurationApp.
     */
    public function testSessionKey(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());

        $sessionKey = $producer->getSessionKey();
        $this->assertNotNull($sessionKey);
        $this->assertMatchesRegularExpression(ConfigurationApp::SESSION_KEY_PATTERN, $sessionKey);
    }

    // ══════════════════════════════════════════════════════════════
    //  exist / get — поиск и создание конфигураций агентов
    // ══════════════════════════════════════════════════════════════

    /**
     * Несуществующий агент — exist() возвращает false.
     */
    public function testExistReturnsFalseForMissing(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());
        $this->assertFalse($producer->exist('nonexistent'));
    }

    /**
     * PHP-файл агента в директории agents/ — exist() возвращает true.
     */
    public function testExistReturnsTrueForPhpAgent(): void
    {
        file_put_contents(
            $this->tmpDir . '/agents/test.php',
            '<?php return ["enableChatHistory" => false, "contextWindow" => 5000];'
        );

        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());
        $this->assertTrue($producer->exist('test'));
    }

    /**
     * JSONC-файл агента в директории agents/ — exist() возвращает true.
     */
    public function testExistReturnsTrueForJsoncAgent(): void
    {
        file_put_contents(
            $this->tmpDir . '/agents/test.jsonc',
            '{"enableChatHistory": false}'
        );

        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());
        $this->assertTrue($producer->exist('test'));
    }

    /**
     * Несуществующий агент — get() возвращает null.
     */
    public function testGetReturnsNullForMissing(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());
        $this->assertNull($producer->get('missing'));
    }

    /**
     * Существующий PHP-файл — get() возвращает ConfigurationAgent
     * с правильным agentName и настройками.
     */
    public function testGetReturnsConfigurationAgent(): void
    {
        file_put_contents(
            $this->tmpDir . '/agents/myagent.php',
            '<?php return ["enableChatHistory" => false, "contextWindow" => 6000];'
        );

        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());

        $cfg = $producer->get('myagent');
        $this->assertInstanceOf(ConfigurationAgent::class, $cfg);
        $this->assertSame('myagent', $cfg->agentName);
        $this->assertSame(6000, $cfg->contextWindow);
    }

    /**
     * Повторный вызов get() возвращает тот же объект из кеша.
     */
    public function testGetCachesResult(): void
    {
        file_put_contents(
            $this->tmpDir . '/agents/cached.php',
            '<?php return ["enableChatHistory" => false];'
        );

        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());

        $first = $producer->get('cached');
        $second = $producer->get('cached');
        $this->assertSame($first, $second);
    }

    /**
     * Кеширование null-результата — повторный get() не ищет файл заново.
     */
    public function testGetCachesNullForMissing(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());

        $this->assertNull($producer->get('missing'));
        $this->assertNull($producer->get('missing'));
    }

    /**
     * При наличии и PHP-, и JSONC-файлов — PHP имеет приоритет.
     */
    public function testPhpPriorityOverJsonc(): void
    {
        file_put_contents(
            $this->tmpDir . '/agents/dual.php',
            '<?php return ["enableChatHistory" => false, "contextWindow" => 1000];'
        );
        file_put_contents(
            $this->tmpDir . '/agents/dual.jsonc',
            '{"enableChatHistory": false, "contextWindow": 2000}'
        );

        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());

        $cfg = $producer->get('dual');
        $this->assertSame(1000, $cfg->contextWindow);
    }

    /**
     * Агент с enableChatHistory = false — история сбрасывается при создании.
     */
    public function testDisabledChatHistoryResetsHistory(): void
    {
        file_put_contents(
            $this->tmpDir . '/agents/nohist.php',
            '<?php return ["enableChatHistory" => false];'
        );

        $dp = new DirPriority([$this->tmpDir]);
        $producer = new AgentProducer($dp, ConfigurationApp::getInstance());
        $cfg = $producer->get('nohist');

        $this->assertFalse($cfg->enableChatHistory);
    }
}
