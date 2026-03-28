<?php

declare(strict_types=1);

namespace Tests\Command;

use app\modules\neuron\classes\command\HelloCommand;
use app\modules\neuron\classes\console\TimedConsoleApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Тесты вывода длительности выполнения команд в {@see TimedConsoleApplication}.
 */
final class TimedConsoleApplicationTest extends TestCase
{
    /**
     * После {@see Application::run()} Symfony выставляет SHELL_VERBOSITY в окружение;
     * без сброса следующий тест может остаться в quiet и потерять вывод.
     */
    protected function tearDown(): void
    {
        if (\function_exists('putenv')) {
            @putenv('SHELL_VERBOSITY');
        }
        unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
    }

    /**
     * Базовый запуск: после hello в буфере есть строка с временем выполнения.
     */
    public function testHelloRunIncludesExecutionTimeInOutput(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput();
        $exitCode = $app->run(new ArrayInput(['command' => 'hello']), $output);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Время выполнения:', $output->fetch());
    }

    /**
     * Граничный случай: при -q строка времени не должна попадать в вывод (тихий режим).
     */
    public function testQuietModeSuppressesExecutionTimeLine(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);
        $exitCode = $app->run(new ArrayInput(['command' => 'hello', '--quiet' => true]), $output);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Время выполнения', $output->fetch());
    }

    /**
     * Формат строки: три знака после запятой и суффикс «с».
     */
    public function testExecutionTimeLineMatchesDecimalPattern(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput();
        $app->run(new ArrayInput(['command' => 'hello']), $output);

        $text = $output->fetch();
        $this->assertMatchesRegularExpression('/Время выполнения:\s+[0-9]+\.[0-9]{3}\s+с/', $text);
    }

    /**
     * Код возврата успешной команды не должен меняться обёрткой.
     */
    public function testExitCodeSuccessFromHelloCommand(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput();
        $exitCode = $app->run(new ArrayInput(['command' => 'hello']), $output);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * Встроенная команда list тоже проходит через doRunCommand и получает строку времени.
     */
    public function testListCommandIncludesExecutionTime(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput();
        $exitCode = $app->run(new ArrayInput(['command' => 'list']), $output);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Время выполнения:', $output->fetch());
    }

    /**
     * Повышенная детализация (-v) не отключает строку времени (не путать с quiet).
     */
    public function testVerbosityVerboseStillShowsExecutionTime(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $exitCode = $app->run(new ArrayInput(['command' => 'hello', '-v' => true]), $output);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Время выполнения:', $output->fetch());
    }

    /**
     * Несуществующая команда: до doRunCommand не доходим — строки времени быть не должно.
     */
    public function testUnknownCommandDoesNotPrintExecutionTimeLine(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput();
        $exitCode = $app->run(new ArrayInput(['command' => 'definitely_missing_command_xyz']), $output);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringNotContainsString('Время выполнения', $output->fetch());
    }

    /**
     * Команда, завершающаяся с ненулевым кодом: время всё равно выводится (finally).
     */
    public function testFailingCommandStillPrintsExecutionTime(): void
    {
        $app = new TimedConsoleApplication('test-app', '0.0.1');
        $app->setAutoExit(false);
        $app->add(new class extends Command {
            protected static $defaultName = 'fail-now';

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return Command::FAILURE;
            }
        });

        $output = new BufferedOutput();
        $exitCode = $app->run(new ArrayInput(['command' => 'fail-now']), $output);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Время выполнения:', $output->fetch());
    }

    /**
     * Заведомо «быстрая» команда: число секунд в разумном диапазоне [0; 5) для стабильности CI.
     */
    public function testReportedSecondsInReasonableRangeForTrivialCommand(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput();
        $app->run(new ArrayInput(['command' => 'hello']), $output);

        if (preg_match('/Время выполнения:\s+([0-9.]+)\s+с/', $output->fetch(), $m) !== 1) {
            $this->fail('Не удалось извлечь число секунд из вывода.');
        }
        $seconds = (float) $m[1];
        $this->assertGreaterThanOrEqual(0.0, $seconds);
        $this->assertLessThan(5.0, $seconds);
    }

    /**
     * Некорректное имя команды с пробелами (заведомо неверный ввод): без строки времени.
     */
    public function testMalformedCommandNameDoesNotPrintExecutionTime(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput();
        $exitCode = $app->run(new ArrayInput(['command' => 'bad name with spaces']), $output);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringNotContainsString('Время выполнения', $output->fetch());
    }

    /**
     * Пустой ввод без имени команды: Symfony запускает команду по умолчанию (list), строка времени ожидаема.
     */
    public function testEmptyInputRunsDefaultListCommandAndShowsExecutionTime(): void
    {
        $app = $this->createAppWithHello();
        $output = new BufferedOutput();
        $exitCode = $app->run(new ArrayInput([]), $output);

        $this->assertSame(0, $exitCode);
        $text = $output->fetch();
        $this->assertStringContainsString('Время выполнения:', $text);
    }

    private function createAppWithHello(): TimedConsoleApplication
    {
        $app = new TimedConsoleApplication('test-app', '0.0.1');
        $app->setAutoExit(false);
        $app->add(new HelloCommand());

        return $app;
    }
}
