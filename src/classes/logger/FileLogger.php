<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\logger;

use Psr\Log\LoggerInterface;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;

use function date;
use function fclose;
use function fopen;
use function is_resource;
use function json_encode;
use function str_replace;

use const JSON_UNESCAPED_UNICODE;

/**
 * Логгер, записывающий сообщения в файл (PSR-3).
 *
 * Принимает путь к файлу. Каждая запись — одна строка
 * в формате: [timestamp] level: message {context_json}.
 */
class FileLogger implements LoggerInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /** @var resource|null Ресурс файла */
    private $stream;

    /** @var bool true, если поток открыт конструктором и должен закрываться при уничтожении */
    private bool $streamOwned = false;

    /**
     * Создаёт логгер, пишущий в файл по указанному пути.
     *
     * Файл открывается в режиме append.
     *
     * @param string $filePath Путь к файлу лога.
     */
    public function __construct(string $filePath)
    {
        $this->stream = fopen($filePath, 'a');
        if ($this->stream === false) {
            throw new \RuntimeException('Не удалось открыть файл для логирования: ' . $filePath);
        }
        $this->streamOwned = true;
    }

    /**
     * Закрывает поток, если он был открыт конструктором.
     */
    public function __destruct()
    {
        if ($this->streamOwned && $this->stream !== null && is_resource($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /** @param array<string, mixed> $context */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->write(LogLevel::EMERGENCY, (string) $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->write(LogLevel::ALERT, (string) $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->write(LogLevel::CRITICAL, (string) $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->write(LogLevel::ERROR, (string) $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->write(LogLevel::WARNING, (string) $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->write(LogLevel::NOTICE, (string) $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->write(LogLevel::INFO, (string) $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->write(LogLevel::DEBUG, (string) $message, $context);
    }

    /**
     * Логирование с произвольным уровнем.
     *
     * @param mixed $level Уровень из Psr\Log\LogLevel
     * @param array<string, mixed> $context
     * @throws InvalidArgumentException Если уровень не строка
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (!is_string($level)) {
            throw new InvalidArgumentException('Level must be a string.');
        }
        $this->write($level, (string) $message, $context);
    }

    /**
     * Записывает одну строку в лог.
     *
     * Формат: [Y-m-d H:i:s] level: message {"context":...}
     * Исключения в context сериализуются в виде сообщения и класса.
     *
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        if ($this->stream === null || !is_resource($this->stream)) {
            return;
        }
        $context     = $this->normalizeContext($context);
        $contextJson = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line        = '[' . date(self::DATE_FORMAT) . '] ' . $level . ': ' . $this->interpolate($message, $context) . $contextJson . "\n";

        // записи лога будем отделять пустой строкой
        $line .= "\n";

        @fwrite($this->stream, $line);
    }

    /**
     * Нормализует контекст: исключение заменяется на массив с message и class.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $out[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                ];
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Подставляет плейсхолдеры {key} из context в сообщение.
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $message = str_replace('{' . $key . '}', (string) $value, $message);
            }
        }
        return $message;
    }
}
