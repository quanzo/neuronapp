<?php

declare(strict_types=1);

namespace Tests\Services;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\helpers\SessionCleanupHelper;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see SessionCleanupHelper}.
 *
 * Проверяем:
 * - вычисление кандидатов файлов по sessionKey;
 * - удаление (и dry-run) с корректной статистикой;
 * - сбор sessionKey как union по `.sessions/.store/.logs`, включая «осиротевшие» данные.
 */
final class SessionCleanupHelperTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_session_cleanup_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);

        $this->resetSingleton();

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
    }

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

    /**
     * Создаёт полный набор файлов для сессии:
     * - история чата;
     * - legacy-история чата с суффиксом `-agent`;
     * - run_state чекпоинт;
     * - var файл + индекс;
     * - лог.
     */
    private function seedFullSessionFiles(string $sessionKey): void
    {
        $safeKey = $this->sanitizeKeyPart($sessionKey);

        file_put_contents($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '.chat', '[]');
        file_put_contents($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '-agent.chat', '[]');

        file_put_contents($this->tmpDir . '/.store/run_state_' . $safeKey . '_default.json', '{"schema":"x"}');
        file_put_contents($this->tmpDir . '/.store/var_' . $safeKey . '_completed.json', '{"schema":"y"}');
        file_put_contents($this->tmpDir . '/.store/var_index_' . $safeKey . '.json', '{"schema":"z"}');

        file_put_contents($this->tmpDir . '/.logs/' . $sessionKey . '.log', 'log');
    }

    private function sanitizeKeyPart(string $value): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $value);
    }

    /**
     * buildSessionFileCandidates() включает chat/run_state/var/index/log и legacy chat, если он есть.
     */
    public function testBuildCandidatesIncludesAllExpectedFiles(): void
    {
        $sessionKey = '20250301-143022-123456-0';
        $this->seedFullSessionFiles($sessionKey);

        $appCfg = ConfigurationApp::getInstance();
        $candidates = SessionCleanupHelper::buildSessionFileCandidates($appCfg, $sessionKey);

        $this->assertNotEmpty($candidates);
        $this->assertContains($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '.chat', $candidates);
        $this->assertContains($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '-agent.chat', $candidates);
        $this->assertContains($this->tmpDir . '/.store/var_index_' . $this->sanitizeKeyPart($sessionKey) . '.json', $candidates);
        $this->assertContains($this->tmpDir . '/.logs/' . $sessionKey . '.log', $candidates);
    }

    /**
     * clearSession() в dry-run не удаляет файлы, но возвращает статистику missing/errors=0.
     */
    public function testClearSessionDryRunDoesNotDeleteFiles(): void
    {
        $sessionKey = '20250302-000001-1-0';
        $this->seedFullSessionFiles($sessionKey);

        $appCfg = ConfigurationApp::getInstance();
        $res = SessionCleanupHelper::clearSession($appCfg, $sessionKey, true);

        $this->assertTrue($res->isDryRun());
        $this->assertSame(0, $res->getDeletedFilesCount());
        $this->assertSame(0, $res->getErrorsCount());

        $this->assertFileExists($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '.chat');
        $this->assertFileExists($this->tmpDir . '/.store/var_index_' . $this->sanitizeKeyPart($sessionKey) . '.json');
        $this->assertFileExists($this->tmpDir . '/.logs/' . $sessionKey . '.log');
    }

    /**
     * clearSession() удаляет все существующие файлы и не считает это ошибкой.
     */
    public function testClearSessionDeletesExistingFiles(): void
    {
        $sessionKey = '20250303-000001-1-0';
        $this->seedFullSessionFiles($sessionKey);

        $appCfg = ConfigurationApp::getInstance();
        $res = SessionCleanupHelper::clearSession($appCfg, $sessionKey, false);

        $this->assertFalse($res->isDryRun());
        $this->assertSame(0, $res->getErrorsCount());
        $this->assertGreaterThanOrEqual(5, $res->getDeletedFilesCount());

        $this->assertFileDoesNotExist($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '.chat');
        $this->assertFileDoesNotExist($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '-agent.chat');
        $this->assertFileDoesNotExist($this->tmpDir . '/.store/var_index_' . $this->sanitizeKeyPart($sessionKey) . '.json');
        $this->assertFileDoesNotExist($this->tmpDir . '/.logs/' . $sessionKey . '.log');
    }

    /**
     * clearSession() идемпотентна: отсутствие файлов не приводит к ошибке, а идёт в missing.
     */
    public function testClearSessionIsIdempotentMissingIsReported(): void
    {
        $sessionKey = '20250304-000001-1-0';

        $appCfg = ConfigurationApp::getInstance();
        $res = SessionCleanupHelper::clearSession($appCfg, $sessionKey, false);

        $this->assertSame(0, $res->getErrorsCount());
        $this->assertSame(0, $res->getDeletedFilesCount());
        $this->assertGreaterThanOrEqual(2, $res->getMissingFilesCount()); // минимум chat + var_index + log
    }

    /**
     * listAllSessionKeysUnion() включает sessionKey из `.sessions` (по neuron_*.chat).
     */
    public function testListAllSessionKeysUnionIncludesSessionsDirKeys(): void
    {
        $k1 = '20250305-101010-111111-0';
        $k2 = '20250305-101010-222222-0';
        file_put_contents($this->tmpDir . '/.sessions/neuron_' . $k1 . '.chat', '[]');
        file_put_contents($this->tmpDir . '/.sessions/neuron_' . $k2 . '.chat', '[]');

        $keys = SessionCleanupHelper::listAllSessionKeysUnion(ConfigurationApp::getInstance());
        $this->assertContains($k1, $keys);
        $this->assertContains($k2, $keys);
    }

    /**
     * listAllSessionKeysUnion() подхватывает «осиротевший» ключ из `.logs`, даже если нет `.sessions`.
     */
    public function testListAllSessionKeysUnionIncludesLogsOrphans(): void
    {
        $k = '20250306-121212-333333-0';
        file_put_contents($this->tmpDir . '/.logs/' . $k . '.log', 'log');

        $keys = SessionCleanupHelper::listAllSessionKeysUnion(ConfigurationApp::getInstance());
        $this->assertContains($k, $keys);
    }

    /**
     * listAllSessionKeysUnion() подхватывает ключ из `.store` по `run_state_*` (орфанный сценарий).
     */
    public function testListAllSessionKeysUnionIncludesStoreRunStateOrphans(): void
    {
        $k = '20250307-131313-444444-0';
        $safe = $this->sanitizeKeyPart($k);
        file_put_contents($this->tmpDir . '/.store/run_state_' . $safe . '_default.json', '{"schema":"x"}');

        $keys = SessionCleanupHelper::listAllSessionKeysUnion(ConfigurationApp::getInstance());
        $this->assertContains($safe, $keys);
    }

    /**
     * listAllSessionKeysUnion() подхватывает ключ из `.store` по `var_index_*` (орфанный сценарий).
     */
    public function testListAllSessionKeysUnionIncludesStoreVarIndexOrphans(): void
    {
        $k = '20250308-141414-555555-0';
        $safe = $this->sanitizeKeyPart($k);
        file_put_contents($this->tmpDir . '/.store/var_index_' . $safe . '.json', '{"schema":"x"}');

        $keys = SessionCleanupHelper::listAllSessionKeysUnion(ConfigurationApp::getInstance());
        $this->assertContains($safe, $keys);
    }

    /**
     * clearSession() удаляет legacy chat `neuron_<key>-agent.chat` вместе с основным.
     */
    public function testClearSessionDeletesLegacyChatFileVariant(): void
    {
        $sessionKey = '20250309-151515-666666-0';
        file_put_contents($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '-agent.chat', '[]');

        $res = SessionCleanupHelper::clearSession(ConfigurationApp::getInstance(), $sessionKey, false);
        $this->assertSame(0, $res->getErrorsCount());
        $this->assertFileDoesNotExist($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '-agent.chat');
    }

    /**
     * clearSession() удаляет var-результаты по маске `var_<safeKey>_*.json`.
     */
    public function testClearSessionDeletesVarFilesByGlob(): void
    {
        $sessionKey = '20250310-161616-777777-0';
        $safeKey = $this->sanitizeKeyPart($sessionKey);
        file_put_contents($this->tmpDir . '/.store/var_' . $safeKey . '_a.json', '{"a":1}');
        file_put_contents($this->tmpDir . '/.store/var_' . $safeKey . '_b.json', '{"b":2}');

        $res = SessionCleanupHelper::clearSession(ConfigurationApp::getInstance(), $sessionKey, false);
        $this->assertSame(0, $res->getErrorsCount());
        $this->assertFileDoesNotExist($this->tmpDir . '/.store/var_' . $safeKey . '_a.json');
        $this->assertFileDoesNotExist($this->tmpDir . '/.store/var_' . $safeKey . '_b.json');
    }
}
