<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\storage;

use app\modules\neuron\classes\dto\mind\MindRecordDto;
use app\modules\neuron\classes\dto\mind\MindSliceEstimateDto;
use app\modules\neuron\classes\neuron\trimmers\TokenCounter;
use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\helpers\MindStorageFilenameHelper;
use DateTimeImmutable;
use DateTimeInterface;
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
use function mkdir;
use function preg_replace;
use function rename;
use function strlen;
use function unlink;
use function usort;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_SH;

/**
 * Файловое UTF-8 хранилище долговременной памяти сообщений пользователя (Markdown-блоки + индекс).
 *
 * Один пользователь — база имён `user_<id>` и файлы `.md`, `.mind.idx`, `.mind.seq`, `.mind.lock`
 * внутри переданной директории `.mind`. Запись и перестроение выполняются под `flock`.
 * Содержимое `.md` не читается целиком в память при выборке одной записи: используется индекс смещений
 * и бинарный поиск по `recordId`.
 *
 * Пример:
 *
 * ```php
 * $mind = new UserMindMarkdownStorage('/home/user/.neuronapp/.mind', 7);
 * $id = $mind->appendMessage('20260412-120000-1-0', 'user', 'Привет');
 * $row = $mind->getByRecordId($id);
 * ```
 */
class UserMindMarkdownStorage
{
    /**
     * Разделитель блоков: две пустые строки подряд (четыре перевода строки).
     */
    private const BLOCK_SEPARATOR = "\n\n\n\n";

    /**
     * Схема JSON в файле последовательности номеров.
     */
    private const SEQ_SCHEMA = 'neuronapp.mind.seq.v1';

    /**
     * @param string     $mindDirectory Абсолютный путь к каталогу `.mind`.
     * @param int|string $userId        Идентификатор пользователя.
     */
    public function __construct(
        private readonly string $mindDirectory,
        private readonly int|string $userId
    ) {
        if (!is_dir($this->mindDirectory)) {
            if (!mkdir($this->mindDirectory, 0777, true) && !is_dir($this->mindDirectory)) {
                throw new RuntimeException('Не удалось создать каталог .mind: ' . $this->mindDirectory);
            }
        }
    }

    /**
     * Добавляет блок сообщения в конец хранилища.
     *
     * @param string             $sessionKey Ключ сессии.
     * @param string             $role       Строковое значение роли NeuronAI.
     * @param string             $bodyPlain  Текст тела (будет нормализован).
     * @param DateTimeImmutable|null $capturedAt Время сообщения; по умолчанию «сейчас».
     *
     * @return int Присвоенный монотонный `recordId`.
     */
    public function appendMessage(string $sessionKey, string $role, string $bodyPlain, ?DateTimeImmutable $capturedAt = null): int
    {
        $capturedAt ??= new DateTimeImmutable('now');
        return (int) $this->withExclusiveLock(function () use ($sessionKey, $role, $bodyPlain, $capturedAt): int {
            $nextId = $this->readNextRecordIdUnlocked();
            $block = $this->composeBlockString($nextId, $capturedAt, $sessionKey, $role, $bodyPlain);
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
     *
     * @param int $recordId Номер записи (record id).
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
     * @param array<int, int> $recordIds Список record id.
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
     * Заменяет содержимое одной или нескольких записей по номерам.
     *
     * @param MindRecordDto[] $replacements Список DTO замены (каждый содержит `recordId`).
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
     * Порядок `$recordIds` может быть любым; каждая запись учитывается не более одного раза.
     *
     * @param array<int, int> $recordIds Номера записей.
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
     * Выполняет колбэк под эксклюзивной блокировкой файла `.mind.lock`.
     *
     * @template T
     * @param callable(): T $callback Логика мутации.
     *
     * @return T
     */
    private function withExclusiveLock(callable $callback): mixed
    {
        return $this->withLock(LOCK_EX, $callback);
    }

    /**
     * Выполняет колбэк под разделяемой блокировкой.
     *
     * @template T
     * @param callable(): T $callback Логика чтения.
     *
     * @return T
     */
    private function withSharedLock(callable $callback): mixed
    {
        return $this->withLock(LOCK_SH, $callback);
    }

    /**
     * Блокировка файла и вызов колбэка.
     *
     * @template T
     * @param int             $lockMode LOCK_EX или LOCK_SH.
     * @param callable(): T   $callback  Операция под замком.
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
     * Перестраивает `.md` и индекс, опционально заменяя записи из карты.
     *
     * @param callable(MindRecordDto): bool $keepPredicate Вернуть true, если запись следует сохранить.
     * @param array<int, MindRecordDto>      $replaceMap     Карта замен по recordId (может быть пустой).
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

    /**
     * Собирает строку блока (без внешнего разделителя между блоками).
     *
     * @param int               $recordId   Номер записи.
     * @param DateTimeImmutable $capturedAt Время записи.
     * @param string            $sessionKey Ключ сессии.
     * @param string            $role       Роль.
     * @param string            $bodyPlain  Сырое тело.
     */
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

    /**
     * Убирает из поля заголовка символы, ломающие однострочный формат.
     *
     * @param string $value Исходное значение.
     */
    private function sanitizeHeaderField(string $value): string
    {
        $v = str_replace(["\t", "\r", "\n"], ' ', $value);

        return trim($v);
    }

    /**
     * Нормализует тело: не более одной пустой строки подряд внутри текста.
     *
     * @param string $body Исходное тело.
     */
    private function normalizeBodyForStorage(string $body): string
    {
        $normalized = preg_replace("/\n{3,}/u", "\n\n", $body);

        return is_string($normalized) ? $normalized : $body;
    }

    /**
     * Разбирает сырую строку блока в DTO.
     *
     * @param string $raw Байты одного блока.
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
            ->setCapturedAt($parts[1])
            ->setSessionKey($parts[2])
            ->setRole($roleField)
            ->setBody($body);
    }

    /**
     * Достаёт recordId из первой строки сырого блока.
     *
     * @param string $raw Сырой блок.
     */
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

    /**
     * Читает байты блока из `.md` без загрузки всего файла.
     *
     * @param int $offset Смещение начала блока.
     * @param int $length Длина блока в байтах.
     */
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
     * Загружает индексные записи с диска.
     *
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
     * Атомарно записывает индекс.
     *
     * @param list<array{recordId:int, offset:int, length:int}> $entries Записи индекса.
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

    /**
     * Читает следующий свободный record id из seq-файла.
     */
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

    /**
     * Записывает следующий record id после последнего выданного.
     *
     * @param int $nextRecordId Первый свободный id для следующей вставки.
     */
    private function writeNextRecordIdUnlocked(int $nextRecordId): void
    {
        $path = $this->getSeqPath();
        $payload = JsonHelper::encodeThrow([
            'schema'       => self::SEQ_SCHEMA,
            'nextRecordId' => max(1, $nextRecordId),
        ]);
        $this->atomicWrite($path, $payload . "\n");
    }

    /**
     * Атомарная запись содержимого файла через временный файл и rename.
     *
     * @param string $path    Целевой путь.
     * @param string $payload Содержимое UTF-8.
     */
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
     * Сортирует индекс по recordId (для бинарного поиска).
     *
     * @param list<array{recordId:int, offset:int, length:int}> $entries Список для сортировки по месту.
     */
    private function sortEntriesByRecordId(array &$entries): void
    {
        usort($entries, static fn(array $a, array $b): int => $a['recordId'] <=> $b['recordId']);
    }

    /**
     * Бинарный поиск записи индекса по recordId.
     *
     * @param list<array{recordId:int, offset:int, length:int}> $entries Список, отсортированный по recordId.
     * @param int                                               $recordId Искомый id.
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

    /**
     * Путь к основному markdown-файлу пользователя.
     */
    private function getMarkdownPath(): string
    {
        return $this->mindDirectory . DIRECTORY_SEPARATOR . $this->getBasename() . '.md';
    }

    /**
     * Путь к индексу смещений.
     */
    private function getIndexPath(): string
    {
        return $this->mindDirectory . DIRECTORY_SEPARATOR . $this->getBasename() . '.mind.idx';
    }

    /**
     * Путь к файлу монотонного счётчика record id.
     */
    private function getSeqPath(): string
    {
        return $this->mindDirectory . DIRECTORY_SEPARATOR . $this->getBasename() . '.mind.seq';
    }

    /**
     * Путь к lock-файлу.
     */
    private function getLockPath(): string
    {
        return $this->mindDirectory . DIRECTORY_SEPARATOR . $this->getBasename() . '.mind.lock';
    }

    /**
     * Безопасное базовое имя файлов для user id.
     */
    private function getBasename(): string
    {
        return MindStorageFilenameHelper::toBasename($this->userId);
    }
}
