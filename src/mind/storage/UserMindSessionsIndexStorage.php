<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\storage;

use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\mind\dto\MindSessionMetaDto;
use RuntimeException;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function preg_match;
use function preg_split;
use function rename;
use function str_replace;
use function trim;

/**
 * Хранилище индекса сессий пользователя: `.mind/<user>/sessions.md`.
 *
 * Формат файла
 * ------------
 * - Первая непустая строка: `schema: neuronapp.mind.sessions.v1`
 * - Далее markdown-таблица со строго фиксированными колонками:
 *   | sessionKey | firstCapturedAt | lastCapturedAt | messageCount | summary | storageKey |
 *
 * Формат intentionally "прост": читается глазами и стабильно парсится машиной.
 *
 * Атомарность
 * -----------
 * Запись выполняется через временный файл + `rename`.
 *
 * Пример:
 *
 * <code>
 * $idx = new UserMindSessionsIndexStorage($paths);
 * $all = $idx->readAll();
 * $idx->upsert($meta);
 * </code>
 */
final class UserMindSessionsIndexStorage
{
    public const string SCHEMA = 'neuronapp.mind.sessions.v1';

    public function __construct(
        private readonly MindPaths $paths,
    ) {
    }

    /**
     * Возвращает список всех метаданных сессий из индекса.
     *
     * @return array<string, MindSessionMetaDto> map: sessionKey => meta
     */
    public function readAll(): array
    {
        $path = $this->paths->getUserSessionsIndexPath();
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return [];
        }

        return $this->parseSessionsMarkdown((string) $content);
    }

    /**
     * Возвращает метаданные одной сессии или null.
     */
    public function get(string $sessionKey): ?MindSessionMetaDto
    {
        $all = $this->readAll();

        return $all[$sessionKey] ?? null;
    }

    /**
     * Вставляет/обновляет строку сессии в индексе.
     */
    public function upsert(MindSessionMetaDto $meta): void
    {
        $all = $this->readAll();
        $all[$meta->getSessionKey()] = $meta;

        $this->writeAll($all);
    }

    /**
     * Атомарно перезаписывает индекс.
     *
     * @param array<string, MindSessionMetaDto> $items map sessionKey => meta
     */
    public function writeAll(array $items): void
    {
        $this->ensureUserDir();

        $path = $this->paths->getUserSessionsIndexPath();
        $payload = $this->renderSessionsMarkdown($items);

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $payload) === false) {
            throw new RuntimeException('Не удалось записать временный sessions.md: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось заменить sessions.md: ' . $path);
        }
    }

    /**
     * Создаёт директории `.mind/<user>/` при необходимости.
     */
    private function ensureUserDir(): void
    {
        $userDir = $this->paths->getUserDir();
        if (!is_dir($userDir)) {
            if (!mkdir($userDir, 0777, true) && !is_dir($userDir)) {
                throw new RuntimeException('Не удалось создать user-dir mind: ' . $userDir);
            }
        }

        $sessionsDir = $this->paths->getUserSessionsDir();
        if (!is_dir($sessionsDir)) {
            if (!mkdir($sessionsDir, 0777, true) && !is_dir($sessionsDir)) {
                throw new RuntimeException('Не удалось создать sessions-dir mind: ' . $sessionsDir);
            }
        }
    }

    /**
     * Парсит markdown индекс сессий.
     *
     * @return array<string, MindSessionMetaDto>
     */
    private function parseSessionsMarkdown(string $markdown): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
        $schemaOk = false;
        $rows = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (!$schemaOk) {
                if ($line === 'schema: ' . self::SCHEMA) {
                    $schemaOk = true;
                }
                continue;
            }

            // Табличные заголовки/разделители пропускаем.
            if (str_starts_with($line, '| sessionKey ') || preg_match('/^\|\s*-+\s*\|/u', $line) === 1) {
                continue;
            }

            if (!str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_map(
                static fn(string $v): string => trim($v),
                explode('|', trim($line, '| '))
            );
            if (count($cells) < 6) {
                continue;
            }

            $dto = (new MindSessionMetaDto())
                ->setSessionKey($cells[0])
                ->setFirstCapturedAt($cells[1])
                ->setLastCapturedAt($cells[2])
                ->setMessageCount((int) $cells[3])
                ->setSummary($this->unescapeCell($cells[4]))
                ->setStorageKey($cells[5]);

            if ($dto->getSessionKey() === '') {
                continue;
            }

            $rows[$dto->getSessionKey()] = $dto;
        }

        return $rows;
    }

    /**
     * Рендерит markdown индекс.
     *
     * @param array<string, MindSessionMetaDto> $items
     */
    private function renderSessionsMarkdown(array $items): string
    {
        // Стабильный порядок для diff/читаемости: по sessionKey.
        ksort($items);

        $out = [];
        $out[] = 'schema: ' . self::SCHEMA;
        $out[] = '';
        $out[] = '| sessionKey | firstCapturedAt | lastCapturedAt | messageCount | summary | storageKey |';
        $out[] = '| --- | --- | --- | ---: | --- | --- |';

        foreach ($items as $meta) {
            $out[] = '| '
                . $meta->getSessionKey() . ' | '
                . $meta->getFirstCapturedAt() . ' | '
                . $meta->getLastCapturedAt() . ' | '
                . (string) $meta->getMessageCount() . ' | '
                . $this->escapeCell($meta->getSummary()) . ' | '
                . $meta->getStorageKey() . ' |';
        }

        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * Экранирует значение ячейки markdown таблицы в однострочный формат.
     */
    private function escapeCell(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        $text = trim($text);
        $text = str_replace('|', '\\|', $text);

        return $text;
    }

    private function unescapeCell(string $text): string
    {
        return str_replace('\\|', '|', $text);
    }
}
