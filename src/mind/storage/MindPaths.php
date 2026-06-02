<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\storage;

use app\modules\neuron\mind\helpers\MindSessionStorageKeyHelper;
use app\modules\neuron\mind\helpers\MindStorageFilenameHelper;

use const DIRECTORY_SEPARATOR;

/**
 * Единая точка истины для путей файлов `.mind` в новой схеме (per-session).
 *
 * Схема директорий
 * ----------------
 * Корень `.mind` задаётся {@see \app\modules\neuron\classes\config\ConfigurationApp::getMindDir()}.
 *
 * Внутри корня создаётся поддиректория пользователя:
 * - `.mind/<userBasename>/`
 *
 * Внутри user-директории:
 * - `sessions.md` — индекс сессий пользователя (машинно-парсимый markdown);
 * - `sessions/` — файлы сессионных хранилищ.
 *
 * Для каждой сессии создаётся набор файлов:
 * - `sessions/<storageKey>.md`
 * - `sessions/<storageKey>.mind.idx`
 * - `sessions/<storageKey>.mind.seq`
 * - `sessions/<storageKey>.mind.lock`
 *
 * Пример:
 *
 * <code>
 * $paths = new MindPaths('/home/user/.neuronapp/.mind', 501);
 * $sessionDir = $paths->getUserSessionsDir();
 * $md = $paths->getSessionMarkdownPath('20260602-120000-123456-501');
 * </code>
 */
final class MindPaths
{
    public function __construct(
        private readonly string $mindRootDir,
        private readonly int|string $userId,
    ) {
    }

    /**
     * Базовое имя пользователя (безопасное).
     */
    public function getUserBasename(): string
    {
        return MindStorageFilenameHelper::toBasename($this->userId);
    }

    /**
     * Директория `.mind/<userBasename>`.
     */
    public function getUserDir(): string
    {
        return $this->mindRootDir . DIRECTORY_SEPARATOR . $this->getUserBasename();
    }

    /**
     * Путь к файлу индекса сессий `sessions.md`.
     */
    public function getUserSessionsIndexPath(): string
    {
        return $this->getUserDir() . DIRECTORY_SEPARATOR . 'sessions.md';
    }

    /**
     * Директория `.mind/<userBasename>/sessions`.
     */
    public function getUserSessionsDir(): string
    {
        return $this->getUserDir() . DIRECTORY_SEPARATOR . 'sessions';
    }

    /**
     * Возвращает storageKey для sessionKey.
     */
    public function getStorageKey(string $sessionKey): string
    {
        return MindSessionStorageKeyHelper::fromSessionKey($sessionKey);
    }

    public function getSessionMarkdownPath(string $sessionKey): string
    {
        $key = $this->getStorageKey($sessionKey);
        return $this->getUserSessionsDir() . DIRECTORY_SEPARATOR . $key . '.md';
    }

    public function getSessionIndexPath(string $sessionKey): string
    {
        $key = $this->getStorageKey($sessionKey);
        return $this->getUserSessionsDir() . DIRECTORY_SEPARATOR . $key . '.mind.idx';
    }

    public function getSessionSeqPath(string $sessionKey): string
    {
        $key = $this->getStorageKey($sessionKey);
        return $this->getUserSessionsDir() . DIRECTORY_SEPARATOR . $key . '.mind.seq';
    }

    public function getSessionLockPath(string $sessionKey): string
    {
        $key = $this->getStorageKey($sessionKey);
        return $this->getUserSessionsDir() . DIRECTORY_SEPARATOR . $key . '.mind.lock';
    }
}
