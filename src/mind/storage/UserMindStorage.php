<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\storage;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\interfaces\MindSessionSummaryRefresherInterface;
use app\modules\neuron\mind\dto\MindRecordDto;
use app\modules\neuron\mind\dto\MindSessionMetaDto;
use app\modules\neuron\mind\dto\MindStorageSummaryRefreshResultDto;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\services\MindSessionSummaryService;
use DateTimeImmutable;

use function file_exists;

/**
 * Высокоуровневое API доступа к долговременной памяти пользователя (per-session).
 *
 * Отвечает за:
 * - открытие per-session storage по sessionKey;
 * - ведение индекса `sessions.md` с метаданными;
 * - миграцию legacy storage (см. {@see LegacyUserMindMigrator});
 * - запуск LLM-суммаризации сессий ({@see refreshSessionSummary}, {@see refreshAllSessionSummaries}).
 *
 * Пример:
 *
 * <code>
 * $paths = new MindPaths($mindDir, $userId);
 * $mind = new UserMindStorage($paths);
 * $mind->appendMessage($sessionKey, 'user', 'Привет');
 * $mind->refreshSessionSummary(ConfigurationApp::getInstance(), $sessionKey);
 * $result = $mind->refreshAllSessionSummaries(ConfigurationApp::getInstance());
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

        // Обновляем индекс (summary заполняется через refreshSessionSummary / подписчик).
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
     * Пересчитывает summary одной сессии через LLM (делегирует {@see MindSessionSummaryService}).
     *
     * @param ConfigurationApp                        $app        Конфигурация приложения.
     * @param string                                  $sessionKey Ключ основной сессии.
     * @param MindSessionSummaryRefresherInterface|null $service  Сервис суммаризации (для тестов).
     *
     * @return bool true, если summary непустой и изменился относительно значения до вызова.
     */
    public function refreshSessionSummary(
        ConfigurationApp $app,
        string $sessionKey,
        ?MindSessionSummaryRefresherInterface $service = null,
    ): bool {
        if (MindSummarySessionKeyHelper::isSummarySession($sessionKey)) {
            return false;
        }

        $metaBefore = $this->index->get($sessionKey);
        if ($metaBefore === null || $metaBefore->getMessageCount() === 0) {
            return false;
        }

        $summaryBefore = $metaBefore->getSummary();

        ($service ?? new MindSessionSummaryService())->refreshSessionSummary($app, $this, $sessionKey);

        $metaAfter = $this->index->get($sessionKey);
        if ($metaAfter === null) {
            return false;
        }

        $summaryAfter = $metaAfter->getSummary();

        return $summaryAfter !== '' && $summaryAfter !== $summaryBefore;
    }

    /**
     * Пересчитывает summary для всех сессий индекса пользователя.
     *
     * Пропускает служебные sessionKey ({@see MindSummarySessionKeyHelper}) и сессии без сообщений.
     *
     * @param ConfigurationApp                        $app     Конфигурация приложения.
     * @param MindSessionSummaryRefresherInterface|null $service Сервис суммаризации (для тестов).
     */
    public function refreshAllSessionSummaries(
        ConfigurationApp $app,
        ?MindSessionSummaryRefresherInterface $service = null,
    ): MindStorageSummaryRefreshResultDto {
        $service ??= new MindSessionSummaryService();
        $result = new MindStorageSummaryRefreshResultDto();

        foreach ($this->index->readAll() as $meta) {
            $sessionKey = $meta->getSessionKey();
            if (MindSummarySessionKeyHelper::isSummarySession($sessionKey)) {
                $result->incrementSkipped();
                continue;
            }
            if ($meta->getMessageCount() === 0) {
                $result->incrementSkipped();
                continue;
            }

            $result->incrementAttempted();
            if ($this->refreshSessionSummary($app, $sessionKey, $service)) {
                $result->incrementUpdated();
            }
        }

        return $result;
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
