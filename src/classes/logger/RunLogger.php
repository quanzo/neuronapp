<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\logger;

use Psr\Log\LoggerInterface;

/**
 * Логгер запусков todolist/skills.
 *
 * Пишет агрегированную статистику по каждому запуску в JSON-формате
 * через переданный PSR-3 логгер. Предполагается использование вместе
 * с {@see FileLogger}, но не зависит от конкретной реализации.
 */
final class RunLogger
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Логирует начало запуска.
     *
     * @param string               $type    Тип запуска (например, 'todolist' или 'skill').
     * @param string               $name    Имя списка/скилла.
     * @param array<string, mixed> $context Дополнительный контекст (agent, session и др.).
     *
     * @return string Уникальный идентификатор запуска (runId).
     */
    public function startRun(string $type, string $name, array $context = []): string
    {
        $runId = $this->generateRunId();

        $payload = [
            'event' => 'run_started',
            'runId' => $runId,
            'type' => $type,
            'name' => $name,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ] + $context;

        $this->logger->info('Run started', $payload);

        return $runId;
    }

    /**
     * Логирует завершение запуска.
     *
     * @param string               $runId   Идентификатор запуска.
     * @param array<string, mixed> $metrics Метрики (steps, toolCalls и т.п.).
     * @param \Throwable|null      $error   Исключение при ошибке выполнения.
     */
    public function finishRun(string $runId, array $metrics = [], ?\Throwable $error = null): void
    {
        $payload = [
            'event' => 'run_finished',
            'runId' => $runId,
            'finishedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'success' => $error === null,
        ] + $metrics;

        if ($error !== null) {
            $payload['error'] = [
                'class' => $error::class,
                'message' => $error->getMessage(),
            ];
            $this->logger->error('Run finished with error', $payload);
        } else {
            $this->logger->info('Run finished successfully', $payload);
        }
    }

    private function generateRunId(): string
    {
        return bin2hex(random_bytes(16));
    }
}

