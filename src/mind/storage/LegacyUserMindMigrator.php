<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\storage;

use app\modules\neuron\mind\dto\MindRecordDto;
use DateTimeImmutable;
use RuntimeException;

use function file_exists;
use function file_get_contents;
use function fopen;
use function fclose;
use function fread;
use function fseek;
use function is_string;
use function preg_split;
use function strlen;
use function trim;
use function usort;

/**
 * Мигратор legacy формата `.mind` (один файл на пользователя) в новый per-session layout.
 *
 * Legacy формат хранит:
 * - `<mindRoot>/<userBasename>.md`
 * - `<mindRoot>/<userBasename>.mind.idx`
 * - `<mindRoot>/<userBasename>.mind.seq`
 * - `<mindRoot>/<userBasename>.mind.lock`
 *
 * Новый формат хранит:
 * - `<mindRoot>/<userBasename>/sessions.md`
 * - `<mindRoot>/<userBasename>/sessions/<storageKey>.*`
 *
 * Миграция считается выполненной, когда создан `sessions.md`.
 *
 * Важно:
 * - мигратор НЕ удаляет legacy файлы; они остаются как backup;
 * - recordId в новых файлах назначаются заново (монотонно в пределах сессии).
 */
final class LegacyUserMindMigrator
{
    public function __construct(
        private readonly string $mindRootDir,
        private readonly int|string $userId,
    ) {
    }

    /**
     * Нужна ли миграция (есть legacy файлы и нет нового `sessions.md`).
     */
    public function isMigrationNeeded(): bool
    {
        $paths = new MindPaths($this->mindRootDir, $this->userId);

        if (file_exists($paths->getUserSessionsIndexPath())) {
            return false;
        }

        $base = $paths->getUserBasename();
        $legacyMd = $this->mindRootDir . DIRECTORY_SEPARATOR . $base . '.md';
        $legacyIdx = $this->mindRootDir . DIRECTORY_SEPARATOR . $base . '.mind.idx';

        return file_exists($legacyMd) && file_exists($legacyIdx);
    }

    /**
     * Выполняет миграцию legacy → per-session.
     */
    public function migrate(): void
    {
        $paths = new MindPaths($this->mindRootDir, $this->userId);
        $base = $paths->getUserBasename();

        $legacyMd = $this->mindRootDir . DIRECTORY_SEPARATOR . $base . '.md';
        $legacyIdx = $this->mindRootDir . DIRECTORY_SEPARATOR . $base . '.mind.idx';

        if (!file_exists($legacyMd) || !file_exists($legacyIdx)) {
            return;
        }

        $entries = $this->readLegacyIndexEntries($legacyIdx);
        if ($entries === []) {
            // Создадим пустой индекс, чтобы второй раз не заходить в миграцию.
            (new UserMindSessionsIndexStorage($paths))->writeAll([]);
            return;
        }

        $mdHandle = fopen($legacyMd, 'rb');
        if ($mdHandle === false) {
            throw new RuntimeException('Не удалось открыть legacy .md: ' . $legacyMd);
        }

        $userMind = new UserMindStorage($paths);

        try {
            foreach ($entries as $e) {
                $raw = $this->readBlockBytes($mdHandle, (int) $e['offset'], (int) $e['length']);
                if ($raw === '') {
                    continue;
                }
                $dto = $this->parseBlockString($raw);
                if ($dto === null) {
                    continue;
                }

                $at = DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $dto->getCapturedAt());
                if ($at === false) {
                    $at = new DateTimeImmutable('now');
                }

                $userMind->appendMessage(
                    sessionKey: $dto->getSessionKey() !== '' ? $dto->getSessionKey() : 'unknown',
                    role: $dto->getRole(),
                    bodyPlain: $dto->getBody(),
                    capturedAt: $at,
                );
            }
        } finally {
            fclose($mdHandle);
        }
    }

    /**
     * @return list<array{recordId:int, offset:int, length:int}>
     */
    private function readLegacyIndexEntries(string $idxPath): array
    {
        $content = file_get_contents($idxPath);
        if ($content === false || trim((string) $content) === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", trim((string) $content)) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $p = explode("\t", $line);
            if (count($p) < 3) {
                continue;
            }
            $out[] = [
                'recordId' => (int) $p[0],
                'offset'   => (int) $p[1],
                'length'   => (int) $p[2],
            ];
        }

        usort($out, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $out;
    }

    private function readBlockBytes(mixed $handle, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        if (fseek($handle, $offset) === -1) {
            return '';
        }
        $data = fread($handle, $length);

        return is_string($data) ? $data : '';
    }

    /**
     * Парсит raw-блок legacy формата в DTO (совместимая схема заголовка).
     */
    private function parseBlockString(string $raw): ?MindRecordDto
    {
        $pos = strpos($raw, "\n\n");
        if ($pos === false) {
            return null;
        }
        $header = substr($raw, 0, $pos);
        $body = substr($raw, $pos + 2);
        $parts = explode("\t", $header);
        if (count($parts) < 4) {
            return null;
        }

        $recordId = (int) $parts[0];
        if ($recordId < 1) {
            return null;
        }

        $roleField = count($parts) === 4 ? $parts[3] : implode("\t", array_slice($parts, 3));

        return (new MindRecordDto())
            ->setRecordId($recordId)
            ->setCapturedAt((string) ($parts[1] ?? ''))
            ->setSessionKey((string) ($parts[2] ?? ''))
            ->setRole((string) $roleField)
            ->setBody((string) $body);
    }
}
