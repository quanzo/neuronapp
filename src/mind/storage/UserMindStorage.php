<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\storage;

use app\modules\neuron\mind\dto\MindRecordDto;
use app\modules\neuron\mind\dto\MindSessionMetaDto;
use DateTimeImmutable;
use RuntimeException;

use function file_exists;

/**
 * Высокоуровневое API доступа к долговременной памяти пользователя (per-session).
 *
 * Отвечает за:
 * - открытие per-session storage по sessionKey;
 * - ведение индекса `sessions.md` с метаданными;
 * - (в следующих шагах) миграцию legacy storage и суммаризацию сессий.
 *
 * Пример:
 *
 * <code>
 * $paths = new MindPaths($mindDir, $userId);
 * $mind = new UserMindStorage($paths);
 * $mind->appendMessage($sessionKey, 'user', 'Привет');
 * $hits = $mind->search($sessionKey, 'имя агента');
 * </code>
 */
final class UserMindStorage
{
    private readonly UserMindSessionsIndexStorage $index;

    public function __construct(
        private readonly MindPaths $paths,
    ) {
        $this->index = new UserMindSessionsIndexStorage($this->paths);
    }

    /**
     * Возвращает MindPaths (для внешних операций).
     */
    public function getPaths(): MindPaths
    {
        return $this->paths;
    }

    /**
     * Возвращает индекс сессий.
     */
    public function getSessionsIndex(): UserMindSessionsIndexStorage
    {
        return $this->index;
    }

    /**
     * Открывает сессионное хранилище.
     */
    public function openSession(string $sessionKey): SessionMindMarkdownStorage
    {
        return new SessionMindMarkdownStorage($this->paths, $sessionKey);
    }

    /**
     * Добавляет сообщение в хранилище конкретной сессии и обновляет метаданные индекса.
     *
     * @return int recordId (в пределах сессии)
     */
    public function appendMessage(
        string $sessionKey,
        string $role,
        string $bodyPlain,
        ?DateTimeImmutable $capturedAt = null,
    ): int {
        $store = $this->openSession($sessionKey);
        $id = $store->appendMessage($role, $bodyPlain, $capturedAt);

        // Обновляем индекс (минимально необходимый набор метаданных).
        // Summary пока пустой — будет добавлено отдельным шагом.
        $capturedAt ??= new DateTimeImmutable('now');
        $iso = $capturedAt->format(\DateTimeInterface::ATOM);

        $storageKey = $this->paths->getStorageKey($sessionKey);
        $existing = $this->index->get($sessionKey);
        if ($existing === null) {
            $meta = (new MindSessionMetaDto())
                ->setSessionKey($sessionKey)
                ->setStorageKey($storageKey)
                ->setFirstCapturedAt($iso)
                ->setLastCapturedAt($iso)
                ->setMessageCount(1)
                ->setSummary('');
            $this->index->upsert($meta);
        } else {
            $existing
                ->setStorageKey($storageKey)
                ->setLastCapturedAt($iso)
                ->setMessageCount($existing->getMessageCount() + 1);
            if ($existing->getFirstCapturedAt() === '') {
                $existing->setFirstCapturedAt($iso);
            }
            $this->index->upsert($existing);
        }

        return $id;
    }

    /**
     * Поиск блоков в рамках одной сессии.
     *
     * @return list<MindRecordDto>
     */
    public function searchSession(string $sessionKey, string $query, ?int $maxChars = 100000): array
    {
        return $this->openSession($sessionKey)->searchBlocks($query, $maxChars);
    }

    /**
     * Признак наличия legacy storage `user_<id>.md` в корне `.mind`.
     */
    public function legacyStorageExists(): bool
    {
        $legacyMd = $this->paths->getUserBasename() . '.md';
        $legacyPath = rtrim($this->paths->getUserDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $legacyMd;

        return file_exists($legacyPath);
    }
}
