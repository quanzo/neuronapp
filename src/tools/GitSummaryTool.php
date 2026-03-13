<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\GitSummaryResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function json_decode;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент получения краткой сводки по git-репозиторию.
 *
 * Использует BashCmdTool для безопасного выполнения:
 * - git status --short --branch
 * - git diff --stat
 * - git log --oneline -N (опционально)
 *
 * и возвращает структурированный результат через {@see GitSummaryResultDto}.
 */
class GitSummaryTool extends ATool
{
    /**
     * Рабочая директория репозитория.
     */
    protected string $workingDirectory;

    /**
     * Количество последних коммитов для git log.
     */
    protected int $logLimit = 10;

    /**
     * @param string $workingDirectory Рабочая директория git-репозитория
     * @param int    $logLimit         Количество коммитов в логах
     * @param string $name             Имя инструмента
     * @param string $description      Описание инструмента
     */
    public function __construct(
        string $workingDirectory = '',
        int $logLimit = 10,
        string $name = 'git_summary',
        string $description = 'Краткая сводка по git-репозиторию (status, diff --stat, log).',
    ) {
        parent::__construct(name: $name, description: $description);

        $this->workingDirectory = $workingDirectory !== '' ? $workingDirectory : (string) getcwd();
        $this->logLimit = $logLimit;
    }

    /**
     * Описание входных параметров (минимальный набор).
     *
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'include_log',
                type: PropertyType::BOOLEAN,
                description: 'Включать ли в результат список последних коммитов (git log --oneline).',
                required: false,
            ),
        ];
    }

    /**
     * Выполняет команды git и возвращает результат.
     *
     * @param bool|null $include_log Включать ли git log
     *
     * @return string JSON-строка с результатом {@see GitSummaryResultDto::toArray()} или сообщением об ошибке
     */
    public function __invoke(?bool $include_log = null): string
    {
        $includeLog = $include_log ?? true;

        $status = $this->runGitCommand('git status --short --branch');
        if ($status['error'] !== null) {
            return json_encode(
                [
                    'error' => 'Ошибка git status: ' . $status['error'],
                ],
                JSON_UNESCAPED_UNICODE
            );
        }

        $diff = $this->runGitCommand('git diff --stat');
        $log = [
            'stdout' => '',
            'error' => null,
        ];

        if ($includeLog) {
            $log = $this->runGitCommand('git log --oneline -' . $this->logLimit);
        }

        $dto = new GitSummaryResultDto(
            workingDir: $this->workingDirectory,
            statusShort: trim($status['stdout']),
            diffStat: trim($diff['stdout']),
            log: trim($log['stdout'])
        );

        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Выполняет git-команду через BashCmdTool и возвращает stdout/ошибку.
     *
     * @param string $commandTemplate Команда без параметров
     *
     * @return array{stdout: string, error: string|null}
     */
    private function runGitCommand(string $commandTemplate): array
    {
        $tool = new BashCmdTool(
            commandTemplate: $commandTemplate,
            name: 'git_summary_cmd',
            description: 'Вспомогательная команда git для GitSummaryTool.',
            defaultTimeout: 20,
            maxOutputSize: 65536,
            workingDirectory: $this->workingDirectory,
            allowedPatterns: ['/^git\\s+/'],
            blockedPatterns: ['/rm\\s+-rf/'],
            env: [],
        );

        $json = $tool->__invoke();

        try {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return [
                'stdout' => '',
                'error' => $e->getMessage(),
            ];
        }

        if (!($decoded['exitCode'] ?? 0) === 0) {
            return [
                'stdout' => (string) ($decoded['stdout'] ?? ''),
                'error' => (string) ($decoded['stderr'] ?? 'Неизвестная ошибка git.'),
            ];
        }

        return [
            'stdout' => (string) ($decoded['stdout'] ?? ''),
            'error' => null,
        ];
    }

    /**
     * Устанавливает рабочую директорию.
     *
     * @return self
     */
    public function setWorkingDirectory(string $workingDirectory): self
    {
        $this->workingDirectory = $workingDirectory;

        return $this;
    }

    /**
     * Устанавливает лимит коммитов для git log.
     *
     * @return self
     */
    public function setLogLimit(int $logLimit): self
    {
        $this->logLimit = $logLimit;

        return $this;
    }
}

