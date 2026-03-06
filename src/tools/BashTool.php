<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\BashResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function array_merge;
use function count;
use function fclose;
use function fread;
use function getcwd;
use function is_resource;
use function json_encode;
use function microtime;
use function preg_match;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function stream_set_blocking;
use function strlen;
use function substr;
use function usleep;

use const JSON_UNESCAPED_UNICODE;
use const SIGTERM;

/**
 * Инструмент выполнения shell-команд в терминале.
 *
 * Позволяет LLM запускать произвольные команды в оболочке системы.
 * Это самый мощный инструмент в наборе, так как даёт прямой доступ к системе,
 * поэтому безопасность обеспечивается несколькими механизмами:
 *
 * - **allowedPatterns** — белый список regex-шаблонов разрешённых команд.
 *   Если массив не пуст, только подходящие команды будут выполнены.
 * - **blockedPatterns** — чёрный список regex-шаблонов запрещённых команд.
 *   Проверяется в первую очередь, даже если команда подходит под белый список.
 * - **defaultTimeout** — ограничение времени выполнения. При превышении
 *   процесс получает SIGTERM, а затем SIGKILL.
 * - **maxOutputSize** — ограничение объёма захваченного вывода.
 *
 * Команда запускается через proc_open() в дочернем процессе, потоки stdout
 * и stderr читаются неблокирующим образом. Интерактивные программы (vim, less)
 * не поддерживаются, так как псевдотерминал не создаётся.
 *
 * Все ограничения и настройки вынесены в свойства класса и могут быть изменены
 * через конструктор или сеттеры.
 *
 * Результат возвращается в виде JSON через {@see BashResultDto}.
 *
 * @see BashResultDto Структура результата выполнения команды
 */
class BashTool extends Tool
{
    /** @var int Таймаут по умолчанию (секунды) */
    protected int $defaultTimeout = 30;

    /** @var int Максимальный размер stdout/stderr (байт) */
    protected int $maxOutputSize = 102400;

    /** @var string Рабочая директория для выполнения команд */
    protected string $workingDirectory;

    /**
     * Regex-шаблоны разрешённых команд.
     * Пустой массив означает «разрешены все».
     *
     * @var string[]
     */
    protected array $allowedPatterns = [];

    /**
     * Regex-шаблоны заблокированных команд.
     *
     * @var string[]
     */
    protected array $blockedPatterns = [];

    /**
     * Дополнительные переменные окружения.
     *
     * @var array<string, string>
     */
    protected array $env = [];

    /**
     * @param int                    $defaultTimeout   Таймаут по умолчанию (секунды)
     * @param int                    $maxOutputSize    Максимальный размер вывода (байт)
     * @param string                 $workingDirectory Рабочая директория
     * @param string[]               $allowedPatterns  Regex-шаблоны разрешённых команд
     * @param string[]               $blockedPatterns  Regex-шаблоны заблокированных команд
     * @param array<string, string>  $env              Дополнительные переменные окружения
     * @param string                 $name             Имя инструмента
     * @param string                 $description      Описание инструмента
     */
    public function __construct(
        int $defaultTimeout = 30,
        int $maxOutputSize = 102400,
        string $workingDirectory = '',
        array $allowedPatterns = [],
        array $blockedPatterns = [],
        array $env = [],
        string $name = 'bash',
        string $description = 'Выполнение shell-команды в терминале. Возвращает stdout, stderr и код завершения.',
    ) {
        parent::__construct(name: $name, description: $description);

        $this->defaultTimeout = $defaultTimeout;
        $this->maxOutputSize = $maxOutputSize;
        $this->workingDirectory = $workingDirectory !== '' ? $workingDirectory : (string) getcwd();
        $this->allowedPatterns = $allowedPatterns;
        $this->blockedPatterns = $blockedPatterns;
        $this->env = $env;
    }

    /**
     * Описание входных параметров инструмента для LLM.
     *
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'command',
                type: PropertyType::STRING,
                description: 'Shell-команда для выполнения.',
                required: true,
            ),
            ToolProperty::make(
                name: 'timeout',
                type: PropertyType::INTEGER,
                description: 'Таймаут выполнения в секундах (необязательно, по умолчанию 30).',
                required: false,
            ),
        ];
    }

    /**
     * Выполняет shell-команду и возвращает результат.
     *
     * Запускает команду через proc_open, мониторит таймаут,
     * захватывает stdout/stderr и код возврата.
     *
     * @param string   $command Команда для выполнения
     * @param int|null $timeout Таймаут в секундах (null — использовать defaultTimeout)
     *
     * @return string JSON-строка с результатом выполнения
     */
    public function __invoke(string $command, ?int $timeout = null): string
    {
        $validationError = $this->validateCommand($command);
        if ($validationError !== null) {
            $dto = new BashResultDto(
                command: $command,
                stdout: '',
                stderr: $validationError,
                exitCode: -1,
                timedOut: false,
            );
            return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
        }

        $effectiveTimeout = $timeout ?? $this->defaultTimeout;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envVars = $this->env !== []
            ? array_merge(getenv(), $this->env)
            : null;

        $process = proc_open($command, $descriptors, $pipes, $this->workingDirectory, $envVars);

        if (!is_resource($process)) {
            $dto = new BashResultDto(
                command: $command,
                stdout: '',
                stderr: 'Не удалось запустить процесс.',
                exitCode: -1,
                timedOut: false,
            );
            return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $startTime = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $stdout .= $chunk;
            }

            $chunk = fread($pipes[2], 8192);
            if ($chunk !== false && $chunk !== '') {
                $stderr .= $chunk;
            }

            if (!$status['running']) {
                break;
            }

            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $effectiveTimeout) {
                $timedOut = true;
                proc_terminate($process, SIGTERM);
                usleep(100_000);
                $termStatus = proc_get_status($process);
                if ($termStatus['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }

            usleep(10_000);
        }

        $remaining1 = @fread($pipes[1], $this->maxOutputSize);
        if ($remaining1 !== false && $remaining1 !== '') {
            $stdout .= $remaining1;
        }
        $remaining2 = @fread($pipes[2], $this->maxOutputSize);
        if ($remaining2 !== false && $remaining2 !== '') {
            $stderr .= $remaining2;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if (strlen($stdout) > $this->maxOutputSize) {
            $stdout = substr($stdout, 0, $this->maxOutputSize) . "\n... [вывод обрезан]";
        }
        if (strlen($stderr) > $this->maxOutputSize) {
            $stderr = substr($stderr, 0, $this->maxOutputSize) . "\n... [вывод обрезан]";
        }

        $dto = new BashResultDto(
            command: $command,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $timedOut ? -1 : $exitCode,
            timedOut: $timedOut,
        );

        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Проверяет команду на соответствие белому/чёрному списку.
     *
     * @param string $command Команда для валидации
     *
     * @return string|null Сообщение об ошибке или null если команда разрешена
     */
    private function validateCommand(string $command): ?string
    {
        if ($command === '') {
            return 'Команда не может быть пустой.';
        }

        foreach ($this->blockedPatterns as $pattern) {
            if (preg_match($pattern, $command)) {
                return "Команда заблокирована правилом безопасности: {$pattern}";
            }
        }

        if ($this->allowedPatterns !== []) {
            foreach ($this->allowedPatterns as $pattern) {
                if (preg_match($pattern, $command)) {
                    return null;
                }
            }
            return 'Команда не соответствует ни одному из разрешённых шаблонов.';
        }

        return null;
    }

    /**
     * Устанавливает таймаут по умолчанию для выполнения команд.
     *
     * Если LLM не передаёт явный timeout в параметрах, используется это значение.
     * При превышении таймаута процесс получает SIGTERM, затем SIGKILL.
     *
     * @param int $defaultTimeout Таймаут в секундах (по умолчанию 30)
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setDefaultTimeout(int $defaultTimeout): self
    {
        $this->defaultTimeout = $defaultTimeout;
        return $this;
    }

    /**
     * Устанавливает максимальный размер захваченного вывода (stdout и stderr).
     *
     * Если вывод превышает лимит, он обрезается с пометкой «[вывод обрезан]».
     * Предотвращает чрезмерное потребление памяти при работе с многословными командами.
     *
     * @param int $maxOutputSize Максимальный размер в байтах (по умолчанию 100 КБ)
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setMaxOutputSize(int $maxOutputSize): self
    {
        $this->maxOutputSize = $maxOutputSize;
        return $this;
    }

    /**
     * Устанавливает рабочую директорию для выполнения команд.
     *
     * Команда будет запущена с этой директорией в качестве cwd.
     *
     * @param string $workingDirectory Абсолютный путь к рабочей директории
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setWorkingDirectory(string $workingDirectory): self
    {
        $this->workingDirectory = $workingDirectory;
        return $this;
    }

    /**
     * Устанавливает белый список regex-шаблонов разрешённых команд.
     *
     * Если массив не пуст, только команды, совпадающие хотя бы с одним шаблоном,
     * будут выполнены. Пустой массив снимает ограничение (разрешены все команды).
     *
     * @param string[] $allowedPatterns Массив regex-шаблонов (например, ['/^echo\b/', '/^ls\b/'])
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setAllowedPatterns(array $allowedPatterns): self
    {
        $this->allowedPatterns = $allowedPatterns;
        return $this;
    }

    /**
     * Устанавливает чёрный список regex-шаблонов запрещённых команд.
     *
     * Проверяется в первую очередь. Если команда совпадает с любым шаблоном,
     * она будет отклонена даже при наличии в белом списке.
     *
     * @param string[] $blockedPatterns Массив regex-шаблонов (например, ['/rm\s+-rf/'])
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setBlockedPatterns(array $blockedPatterns): self
    {
        $this->blockedPatterns = $blockedPatterns;
        return $this;
    }

    /**
     * Устанавливает дополнительные переменные окружения для дочернего процесса.
     *
     * Переменные добавляются к текущему окружению (getenv()).
     * Если передан пустой массив, используется окружение родительского процесса.
     *
     * @param array<string, string> $env Ассоциативный массив «имя => значение»
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setEnv(array $env): self
    {
        $this->env = $env;
        return $this;
    }
}
