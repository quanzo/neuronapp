<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\storage;

use app\modules\neuron\mind\dto\MindRecordDto;
use app\modules\neuron\mind\dto\MindSliceEstimateDto;
use app\modules\neuron\classes\neuron\trimmers\TokenCounter;
use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\helpers\MarkdownChunckHelper;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use RuntimeException;

use function fclose;
use function fflush;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function fopen;
use function fread;
use function fwrite;
use function fseek;
use function implode;
use function is_dir;
use function mb_strlen;
use function mkdir;
use function preg_match_all;
use function preg_replace;
use function rename;
use function strlen;
use function trim;
use function unlink;
use function usort;

use const LOCK_EX;
use const LOCK_SH;
use const PREG_SET_ORDER;

/**
 * Файловое UTF-8 хранилище долговременной памяти одной сессии (Markdown-блоки + индекс).
 *
 * В отличие от legacy {@see \app\modules\neuron\classes\storage\UserMindMarkdownStorage}:
 * - запись ведётся в отдельный набор файлов на sessionKey;
 * - recordId монотонен в пределах одной сессии (начинается с 1).
 *
 * Формат блока совместим с legacy:
 * 1) Первая строка: `recordId<TAB>capturedAt(ATOM)<TAB>sessionKey<TAB>role`
 * 2) Пустая строка
 * 3) Тело сообщения
 * Между блоками — две пустые строки (`\\n\\n\\n\\n`).
 *
 * Пример:
 *
 * <code>
 * $paths = new MindPaths('/home/user/.neuronapp/.mind', 501);
 * $s = new SessionMindMarkdownStorage($paths, '20260602-120000-123456-501');
 * $id = $s->appendMessage('user', 'Привет');
 * </code>
 */
final class SessionMindMarkdownStorage
{
    /**
     * Разделитель блоков: две пустые строки подряд (четыре перевода строки).
     */
    private const BLOCK_SEPARATOR = "\n\n\n\n";

    /**
     * Схема JSON в файле последовательности номеров.
     */
    private const SEQ_SCHEMA = 'neuronapp.mind.seq.v1';

    public function __construct(
        private readonly MindPaths $paths,
        private readonly string $sessionKey,
    ) {
        $this->ensureDirs();
    }

    /**
     * Возвращает sessionKey текущего storage.
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Добавляет блок сообщения в конец хранилища.
     *
     * @param string                  $role      Строковое значение роли NeuronAI.
     * @param string                  $bodyPlain Текст тела (будет нормализован).
     * @param DateTimeImmutable|null  $capturedAt Время сообщения; по умолчанию «сейчас».
     *
     * @return int Присвоенный монотонный `recordId` (в пределах сессии).
     */
    public function appendMessage(string $role, string $bodyPlain, ?DateTimeImmutable $capturedAt = null): int
    {
        $capturedAt ??= new DateTimeImmutable('now');

        return (int) $this->withExclusiveLock(function () use ($role, $bodyPlain, $capturedAt): int {
            $nextId = $this->readNextRecordIdUnlocked();
            $block = $this->composeBlockString($nextId, $capturedAt, $this->sessionKey, $role, $bodyPlain);
            $blockLen = strlen($block);

            $mdPath = $this->getMarkdownPath();
            $currentSize = file_exists($mdPath) ? (int) filesize($mdPath) : 0;
            $offset = $currentSize === 0 ? 0 : $currentSize + strlen(self::BLOCK_SEPARATOR);

            $handle = fopen($mdPath, 'ab');
            if ($handle === false) {
                throw new RuntimeException('Не удалось открыть файл памяти для записи: ' . $mdPath);
            }
            try {
                if ($currentSize > 0) {
                    if (fwrite($handle, self::BLOCK_SEPARATOR) === false) {
                        throw new RuntimeException('Ошибка записи разделителя блока.');
                    }
                }
                if (fwrite($handle, $block) === false) {
                    throw new RuntimeException('Ошибка записи блока.');
                }
                fflush($handle);
            } finally {
                fclose($handle);
            }

            $entries = $this->loadIndexEntriesUnlocked();
            $entries[] = [
                'recordId' => $nextId,
                'offset'   => $offset,
                'length'   => $blockLen,
            ];

            $this->sortEntriesByRecordId($entries);
            $this->writeIndexAtomicUnlocked($entries);
            $this->writeNextRecordIdUnlocked($nextId + 1);

            return $nextId;
        });
    }

    /**
     * Возвращает запись по номеру, используя бинарный поиск по индексу.
     */
    public function getByRecordId(int $recordId): ?MindRecordDto
    {
        return $this->withSharedLock(function () use ($recordId): ?MindRecordDto {
            $entries = $this->loadIndexEntriesUnlocked();
            $this->sortEntriesByRecordId($entries);
            $found = $this->binarySearchEntry($entries, $recordId);
            if ($found === null) {
                return null;
            }

            $raw = $this->readBlockBytesUnlocked((int) $found['offset'], (int) $found['length']);
            if ($raw === '') {
                return null;
            }

            return $this->parseBlockString($raw);
        });
    }

    /**
     * Удаляет одну или несколько записей по номерам.
     *
     * @param array<int, int> $recordIds
     */
    public function deleteByRecordIds(array $recordIds): void
    {
        if ($recordIds === []) {
            return;
        }

        $deleteSet = [];
        foreach ($recordIds as $id) {
            $deleteSet[(int) $id] = true;
        }

        $this->withExclusiveLock(function () use ($deleteSet): void {
            $this->rebuildStorageKeepingFilter(static function (MindRecordDto $dto) use ($deleteSet): bool {
                return !isset($deleteSet[$dto->getRecordId()]);
            });
        });
    }

    /**
     * @param MindRecordDto[] $replacements
     */
    public function replaceByRecordIds(array $replacements): void
    {
        if ($replacements === []) {
            return;
        }

        $map = [];
        foreach ($replacements as $dto) {
            if (!$dto instanceof MindRecordDto) {
                throw new RuntimeException('Ожидался MindRecordDto.');
            }
            $map[$dto->getRecordId()] = $dto;
        }

        $this->withExclusiveLock(function () use ($map): void {
            $entries = $this->loadIndexEntriesUnlocked();
            $existing = [];
            foreach ($entries as $e) {
                $existing[$e['recordId']] = true;
            }
            foreach (array_keys($map) as $rid) {
                if (!isset($existing[$rid])) {
                    throw new RuntimeException('Запись для замены не найдена: recordId=' . $rid);
                }
            }

            $this->rebuildStorageKeepingFilter(static function (): bool {
                return true;
            }, $map);
        });
    }

    /**
     * Оценивает суммарный размер среза записей в символах UTF-8 и токенах.
     *
     * @param array<int, int> $recordIds
     */
    public function estimateSlice(array $recordIds): MindSliceEstimateDto
    {
        $unique = [];
        foreach ($recordIds as $id) {
            $unique[(int) $id] = true;
        }
        $ids = array_keys($unique);

        return $this->withSharedLock(function () use ($ids): MindSliceEstimateDto {
            $entries = $this->loadIndexEntriesUnlocked();
            $this->sortEntriesByRecordId($entries);

            $chars = 0;
            $tokens = 0;
            $counter = new TokenCounter();

            foreach ($ids as $recordId) {
                $found = $this->binarySearchEntry($entries, $recordId);
                if ($found === null) {
                    continue;
                }
                $raw = $this->readBlockBytesUnlocked((int) $found['offset'], (int) $found['length']);
                if ($raw === '') {
                    continue;
                }
                $dto = $this->parseBlockString($raw);
                if ($dto === null) {
                    continue;
                }
                $chars += mb_strlen($raw, 'UTF-8');

                $roleEnum = MessageRole::tryFrom($dto->getRole()) ?? MessageRole::USER;
                $msg = new NeuronMessage($roleEnum, $dto->getBody());
                $tokens += $counter->count($msg);
            }

            return (new MindSliceEstimateDto())
                ->setCharacterCount($chars)
                ->setTokenCount($tokens);
        });
    }

    /**
     * Ищет блоки памяти по тексту или regex, ранжирует по числу и «весу» совпадений, ограничивает суммарный размер.
     *
     * @param string   $query
     * @param int|null $maxChars
     *
     * @return list<MindRecordDto>
     */
    public function searchBlocks(string $query, ?int $maxChars = 100000): array
    {
        if ($query === '') {
            throw new InvalidArgumentException('Параметр query не должен быть пустым.');
        }
        if ($maxChars !== null && $maxChars <= 0) {
            throw new InvalidArgumentException('Параметр maxChars должен быть больше 0 или null.');
        }

        $regex = MarkdownChunckHelper::buildLineRegex($query, false);

        return $this->withSharedLock(function () use ($regex, $maxChars): array {
            $entries = $this->loadIndexEntriesUnlocked();
            usort($entries, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']);

            /** @var list<array{raw: string, dto: MindRecordDto, matches: int, matchCharLen: int, recordId: int, utf8Len: int}> $candidates */
            $candidates = [];
            foreach ($entries as $entry) {
                $raw = $this->readBlockBytesUnlocked((int) $entry['offset'], (int) $entry['length']);
                if ($raw === '') {
                    continue;
                }
                $score = $this->scoreRawBlock($raw, $regex);
                if ($score === null) {
                    continue;
                }
                $dto = $this->parseBlockString($raw);
                if ($dto === null) {
                    continue;
                }
                $candidates[] = [
                    'raw'          => $raw,
                    'dto'          => $dto,
                    'matches'      => $score['matches'],
                    'matchCharLen' => $score['matchCharLen'],
                    'recordId'     => $dto->getRecordId(),
                    'utf8Len'      => mb_strlen($raw, 'UTF-8'),
                ];
            }

            usort(
                $candidates,
                static function (array $a, array $b): int {
                    if ($a['matches'] !== $b['matches']) {
                        return $b['matches'] <=> $a['matches'];
                    }
                    if ($a['matchCharLen'] !== $b['matchCharLen']) {
                        return $b['matchCharLen'] <=> $a['matchCharLen'];
                    }

                    return $b['recordId'] <=> $a['recordId'];
                }
            );

            $out = [];
            $totalUtf8 = 0;
            foreach ($candidates as $c) {
                if ($maxChars !== null && $totalUtf8 + $c['utf8Len'] > $maxChars) {
                    continue;
                }
                $out[] = $c['dto'];
                $totalUtf8 += $c['utf8Len'];
            }

            return $out;
        });
    }

    /**
     * Возвращает все записи сессии в порядке хранения (по offset).
     *
     * Важно: предназначено для суммаризации/инструментов. Для точечного доступа используйте
     * {@see getByRecordId()}.
     *
     * @param int|null $maxRecords Ограничение числа записей (с конца/начала не обрезает; просто прекращает после N).
     *
     * @return list<MindRecordDto>
     */
    public function readAll(?int $maxRecords = null): array
    {
        return $this->withSharedLock(function () use ($maxRecords): array {
            $entries = $this->loadIndexEntriesUnlocked();
            usort($entries, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']);

            $out = [];
            $n = 0;
            foreach ($entries as $entry) {
                if ($maxRecords !== null && $n >= $maxRecords) {
                    break;
                }
                $raw = $this->readBlockBytesUnlocked((int) $entry['offset'], (int) $entry['length']);
                if ($raw === '') {
                    continue;
                }
                $dto = $this->parseBlockString($raw);
                if ($dto === null) {
                    continue;
                }
                $out[] = $dto;
                $n++;
            }

            return $out;
        });
    }

    /**
     * @return array{matches: int, matchCharLen: int}|null
     */
    private function scoreRawBlock(string $raw, string $regex): ?array
    {
        $n = @preg_match_all($regex, $raw, $matches, PREG_SET_ORDER);
        if ($n === false || $n < 1) {
            return null;
        }

        $matchCharLen = 0;
        foreach ($matches as $set) {
            $matchCharLen += mb_strlen($set[0], 'UTF-8');
        }

        return [
            'matches'      => $n,
            'matchCharLen' => $matchCharLen,
        ];
    }

    /**
     * @template T
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withExclusiveLock(callable $callback): mixed
    {
        return $this->withLock(LOCK_EX, $callback);
    }

    /**
     * @template T
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withSharedLock(callable $callback): mixed
    {
        return $this->withLock(LOCK_SH, $callback);
    }

    /**
     * @template T
     * @param int $lockMode
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withLock(int $lockMode, callable $callback): mixed
    {
        $lockPath = $this->getLockPath();
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть lock-файл: ' . $lockPath);
        }
        try {
            if (!flock($handle, $lockMode)) {
                throw new RuntimeException('Не удалось получить flock: ' . $lockPath);
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param callable(MindRecordDto): bool $keepPredicate
     * @param array<int, MindRecordDto> $replaceMap
     */
    private function rebuildStorageKeepingFilter(callable $keepPredicate, array $replaceMap = []): void
    {
        $ordered = $this->loadIndexEntriesUnlocked();
        usort($ordered, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $blocks = [];
        $newEntries = [];
        $cursor = 0;

        foreach ($ordered as $entry) {
            $raw = $this->readBlockBytesUnlocked((int) $entry['offset'], (int) $entry['length']);
            $dto = $this->parseBlockString($raw);
            if ($dto === null) {
                continue;
            }

            if (!$keepPredicate($dto)) {
                continue;
            }

            if (isset($replaceMap[$dto->getRecordId()])) {
                $r = $replaceMap[$dto->getRecordId()];
                $at = new DateTimeImmutable($r->getCapturedAt());
                $raw = $this->composeBlockString(
                    $dto->getRecordId(),
                    $at,
                    $r->getSessionKey(),
                    $r->getRole(),
                    $r->getBody()
                );
            }

            $blocks[] = $raw;
        }

        $mdPath = $this->getMarkdownPath();
        $tmpMd = $mdPath . '.tmp.' . bin2hex(random_bytes(4));
        $h = fopen($tmpMd, 'wb');
        if ($h === false) {
            throw new RuntimeException('Не удалось создать временный файл .md');
        }

        $first = true;
        foreach ($blocks as $raw) {
            if (!$first) {
                fwrite($h, self::BLOCK_SEPARATOR);
                $cursor += strlen(self::BLOCK_SEPARATOR);
            }
            $first = false;

            $len = strlen($raw);
            $rid = $this->parseRecordIdFromRaw($raw);
            if ($rid === null) {
                continue;
            }
            $newEntries[] = ['recordId' => $rid, 'offset' => $cursor, 'length' => $len];
            fwrite($h, $raw);
            $cursor += $len;
        }
        fclose($h);

        if (!rename($tmpMd, $mdPath)) {
            @unlink($tmpMd);
            throw new RuntimeException('Не удалось заменить файл .md');
        }

        $this->sortEntriesByRecordId($newEntries);
        $this->writeIndexAtomicUnlocked($newEntries);
    }

    private function composeBlockString(
        int $recordId,
        DateTimeImmutable $capturedAt,
        string $sessionKey,
        string $role,
        string $bodyPlain
    ): string {
        $line1 = implode("\t", [
            (string) $recordId,
            $capturedAt->format(DateTimeInterface::ATOM),
            $this->sanitizeHeaderField($sessionKey),
            $this->sanitizeHeaderField($role),
        ]);

        $body = $this->normalizeBodyForStorage($bodyPlain);

        return $line1 . "\n\n" . $body;
    }

    private function sanitizeHeaderField(string $value): string
    {
        $v = str_replace(["\t", "\r", "\n"], ' ', $value);

        return trim($v);
    }

    private function normalizeBodyForStorage(string $body): string
    {
        $normalized = preg_replace("/\n{3,}/u", "\n\n", $body);

        return is_string($normalized) ? $normalized : $body;
    }

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
            ->setCapturedAt($parts[1])
            ->setSessionKey($parts[2])
            ->setRole($roleField)
            ->setBody($body);
    }

    private function parseRecordIdFromRaw(string $raw): ?int
    {
        $nl = strpos($raw, "\n");
        if ($nl === false) {
            return null;
        }
        $line = substr($raw, 0, $nl);
        $tab = strpos($line, "\t");
        if ($tab === false) {
            return null;
        }
        $id = (int) substr($line, 0, $tab);

        return $id > 0 ? $id : null;
    }

    private function readBlockBytesUnlocked(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $path = $this->getMarkdownPath();
        if (!file_exists($path)) {
            return '';
        }
        $h = fopen($path, 'rb');
        if ($h === false) {
            return '';
        }
        try {
            if (fseek($h, $offset) === -1) {
                return '';
            }
            $data = fread($h, $length);

            return is_string($data) ? $data : '';
        } finally {
            fclose($h);
        }
    }

    /**
     * @return list<array{recordId:int, offset:int, length:int}>
     */
    private function loadIndexEntriesUnlocked(): array
    {
        $path = $this->getIndexPath();
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }
        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        $out = [];
        foreach ($lines as $line) {
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

        return $out;
    }

    /**
     * @param list<array{recordId:int, offset:int, length:int}> $entries
     */
    private function writeIndexAtomicUnlocked(array $entries): void
    {
        $path = $this->getIndexPath();
        $lines = [];
        foreach ($entries as $e) {
            $lines[] = $e['recordId'] . "\t" . $e['offset'] . "\t" . $e['length'];
        }
        $payload = implode("\n", $lines) . ($lines === [] ? '' : "\n");
        $this->atomicWrite($path, $payload);
    }

    private function readNextRecordIdUnlocked(): int
    {
        $path = $this->getSeqPath();
        if (!file_exists($path)) {
            return 1;
        }
        $json = file_get_contents($path);
        if ($json === false || trim((string) $json) === '') {
            return 1;
        }
        /** @var array<string, mixed> $data */
        $data = JsonHelper::decodeAssociativeThrow((string) $json);
        $next = (int) ($data['nextRecordId'] ?? 1);

        return max(1, $next);
    }

    private function writeNextRecordIdUnlocked(int $nextRecordId): void
    {
        $path = $this->getSeqPath();
        $payload = JsonHelper::encodeThrow([
            'schema'       => self::SEQ_SCHEMA,
            'nextRecordId' => max(1, $nextRecordId),
        ]);
        $this->atomicWrite($path, $payload . "\n");
    }

    private function atomicWrite(string $path, string $payload): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $payload) === false) {
            throw new RuntimeException('Не удалось записать временный файл: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось переименовать временный файл в: ' . $path);
        }
    }

    /**
     * @param list<array{recordId:int, offset:int, length:int}> $entries
     */
    private function sortEntriesByRecordId(array &$entries): void
    {
        usort($entries, static fn(array $a, array $b): int => $a['recordId'] <=> $b['recordId']);
    }

    /**
     * @param list<array{recordId:int, offset:int, length:int}> $entries
     *
     * @return array{recordId:int, offset:int, length:int}|null
     */
    private function binarySearchEntry(array $entries, int $recordId): ?array
    {
        $low = 0;
        $high = count($entries) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $cmp = $entries[$mid]['recordId'] <=> $recordId;
            if ($cmp === 0) {
                return $entries[$mid];
            }
            if ($cmp < 0) {
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return null;
    }

    private function ensureDirs(): void
    {
        $userDir = $this->paths->getUserDir();
        if (!is_dir($userDir)) {
            if (!mkdir($userDir, 0777, true) && !is_dir($userDir)) {
                throw new RuntimeException('Не удалось создать каталог user mind: ' . $userDir);
            }
        }
        $sessionsDir = $this->paths->getUserSessionsDir();
        if (!is_dir($sessionsDir)) {
            if (!mkdir($sessionsDir, 0777, true) && !is_dir($sessionsDir)) {
                throw new RuntimeException('Не удалось создать каталог sessions mind: ' . $sessionsDir);
            }
        }
    }

    private function getMarkdownPath(): string
    {
        return $this->paths->getSessionMarkdownPath($this->sessionKey);
    }

    private function getIndexPath(): string
    {
        return $this->paths->getSessionIndexPath($this->sessionKey);
    }

    private function getSeqPath(): string
    {
        return $this->paths->getSessionSeqPath($this->sessionKey);
    }

    private function getLockPath(): string
    {
        return $this->paths->getSessionLockPath($this->sessionKey);
    }
}
