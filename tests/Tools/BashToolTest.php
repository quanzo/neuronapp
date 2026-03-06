<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\tools\BashTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;

use const DIRECTORY_SEPARATOR;

/**
 * Тесты для {@see BashTool}.
 *
 * Проверяют корректность выполнения shell-команд, включая:
 * - выполнение простой команды (echo) с exitCode=0
 * - захват ненулевого кода возврата
 * - захват stderr
 * - принудительное завершение по таймауту
 * - переопределение таймаута параметром timeout
 * - отклонение пустой команды
 * - блокировку команд по blockedPatterns
 * - ограничение по allowedPatterns
 * - использование рабочей директории
 * - обрезку длинного вывода по maxOutputSize
 * - передачу переменных окружения через env
 * - выполнение команд с пайпами
 */
final class BashToolTest extends TestCase
{
    /**
     * Путь к временной директории, создаваемой для каждого теста.
     *
     * @var string
     */
    private string $tempDir;

    /**
     * Создаёт уникальную временную директорию перед каждым тестом.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bash_tool_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    /**
     * Удаляет временную директорию и всё её содержимое после каждого теста.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    /**
     * Проверяет выполнение простой команды echo с exitCode=0 и timedOut=false.
     *
     * @return void
     */
    public function testExecutesSimpleCommand(): void
    {
        $tool = new BashTool();
        $json = $tool->__invoke('echo "Hello World"');
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('Hello World', $data['stdout']);
        $this->assertFalse($data['timedOut']);
    }

    /**
     * Проверяет захват ненулевого кода возврата (exit 42).
     *
     * @return void
     */
    public function testCapturesNonZeroExitCode(): void
    {
        $tool = new BashTool();
        $json = $tool->__invoke('exit 42');
        $data = json_decode($json, true);

        $this->assertSame(42, $data['exitCode']);
        $this->assertFalse($data['timedOut']);
    }

    /**
     * Проверяет захват вывода в stderr через перенаправление >&2.
     *
     * @return void
     */
    public function testCapturesStderr(): void
    {
        $tool = new BashTool();
        $json = $tool->__invoke('echo "err_msg" >&2');
        $data = json_decode($json, true);

        $this->assertStringContainsString('err_msg', $data['stderr']);
    }

    /**
     * Проверяет принудительное завершение по таймауту (defaultTimeout=1, sleep 30).
     *
     * Ожидается timedOut=true и exitCode=-1.
     *
     * @return void
     */
    public function testTimesOut(): void
    {
        $tool = new BashTool(defaultTimeout: 1);
        $json = $tool->__invoke('sleep 30');
        $data = json_decode($json, true);

        $this->assertTrue($data['timedOut']);
        $this->assertSame(-1, $data['exitCode']);
    }

    /**
     * Проверяет, что явный параметр timeout переопределяет defaultTimeout.
     *
     * defaultTimeout=60, но timeout=1 должен вызвать таймаут.
     *
     * @return void
     */
    public function testCustomTimeoutOverridesDefault(): void
    {
        $tool = new BashTool(defaultTimeout: 60);
        $json = $tool->__invoke('sleep 30', 1);
        $data = json_decode($json, true);

        $this->assertTrue($data['timedOut']);
    }

    /**
     * Проверяет, что пустая команда возвращает exitCode=-1 и непустой stderr.
     *
     * @return void
     */
    public function testEmptyCommandReturnsError(): void
    {
        $tool = new BashTool();
        $json = $tool->__invoke('');
        $data = json_decode($json, true);

        $this->assertSame(-1, $data['exitCode']);
        $this->assertNotEmpty($data['stderr']);
    }

    /**
     * Проверяет блокировку команды по blockedPatterns.
     *
     * Команда «ls -l /» должна быть отклонена шаблоном /ls\s+-l/.
     *
     * @return void
     */
    public function testBlockedPatternRejectsCommand(): void
    {
        $tool = new BashTool(blockedPatterns: ['/ls\s+-l/']);
        $json = $tool->__invoke('ls -l /');
        $data = json_decode($json, true);

        $this->assertSame(-1, $data['exitCode']);
        $this->assertStringContainsString('заблокирована', $data['stderr']);
    }

    /**
     * Проверяет ограничение по allowedPatterns.
     *
     * Команда «echo» разрешена, а «ls» — нет.
     *
     * @return void
     */
    public function testAllowedPatternsRestrictCommands(): void
    {
        $tool = new BashTool(allowedPatterns: ['/^echo\b/']);

        $json = $tool->__invoke('echo "allowed"');
        $data = json_decode($json, true);
        $this->assertSame(0, $data['exitCode']);

        $json = $tool->__invoke('ls -la');
        $data = json_decode($json, true);
        $this->assertSame(-1, $data['exitCode']);
        $this->assertStringContainsString('не соответствует', $data['stderr']);
    }

    /**
     * Проверяет, что workingDirectory корректно устанавливает cwd для команды.
     *
     * @return void
     */
    public function testWorkingDirectoryIsRespected(): void
    {
        $tool = new BashTool(workingDirectory: $this->tempDir);
        $json = $tool->__invoke('pwd');
        $data = json_decode($json, true);

        $this->assertStringContainsString($this->tempDir, $data['stdout']);
    }

    /**
     * Проверяет обрезку вывода при превышении maxOutputSize.
     *
     * Генерируется длинный вывод, а maxOutputSize ограничен 50 байтами.
     *
     * @return void
     */
    public function testOutputTruncation(): void
    {
        $tool = new BashTool(maxOutputSize: 50);
        $json = $tool->__invoke('python3 -c "print(\'A\' * 200)" 2>/dev/null || echo ' . str_repeat('A', 200));
        $data = json_decode($json, true);

        $this->assertLessThanOrEqual(100, strlen($data['stdout']));
    }

    /**
     * Проверяет, что сеттеры корректно обновляют свойства, включая env.
     *
     * Переменная TEST_VAR должна быть доступна в дочернем процессе.
     *
     * @return void
     */
    public function testSettersUpdateProperties(): void
    {
        $tool = new BashTool();
        $tool->setDefaultTimeout(5)
             ->setMaxOutputSize(1024)
             ->setWorkingDirectory($this->tempDir)
             ->setAllowedPatterns([])
             ->setBlockedPatterns([])
             ->setEnv(['TEST_VAR' => 'hello']);

        $json = $tool->__invoke('echo $TEST_VAR');
        $data = json_decode($json, true);

        $this->assertStringContainsString('hello', $data['stdout']);
    }

    /**
     * Проверяет выполнение составных команд с пайпом (echo | tr).
     *
     * @return void
     */
    public function testMultipleCommandsWithPipe(): void
    {
        $tool = new BashTool();
        $json = $tool->__invoke('echo "abc" | tr "a" "x"');
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('xbc', $data['stdout']);
    }

    /**
     * Рекурсивно удаляет директорию и всё её содержимое.
     *
     * @param string $dir Путь к директории для удаления
     *
     * @return void
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
