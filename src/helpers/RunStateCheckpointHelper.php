<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\run\RunStateDto;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_replace;
use function rename;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Хелпер чтения и записи чекпоинтов состояния выполнения run (TodoList) в рамках сессии.
 *
 * Сохраняет состояние в файлы .store/run_state_{sessionKey}_{agentName}.json.
 * Запись выполняется атомарно (временный файл + rename) во избежание битых файлов при сбое.
 */
final class RunStateCheckpointHelper
{
    /**
     * Формирует безопасное имя файла чекпоинта по ключу сессии и имени агента.
     *
     * Недопустимые для файловой системы символы заменяются на подчёркивание.
     *
     * @param string $sessionKey Базовый ключ сессии (формат buildSessionKey).
     * @param string $agentName  Имя агента.
     * @return string Имя файла без пути (run_state_*.json).
     */
    public static function fileName(string $sessionKey, string $agentName): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionKey);
        $safeAgent = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $agentName ?: 'unknown');
        return 'run_state_' . $safeKey . '_' . $safeAgent . '.json';
    }

    /**
     * Возвращает полный путь к файлу чекпоинта.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $agentName  Имя агента.
     * @return string Абсолютный путь к файлу.
     */
    public static function filePath(string $sessionKey, string $agentName): string
    {
        $storeDir = ConfigurationApp::getInstance()->getStoreDir();
        return $storeDir . DIRECTORY_SEPARATOR . self::fileName($sessionKey, $agentName);
    }

    /**
     * Читает состояние run из файла чекпоинта.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $agentName  Имя агента.
     * @return RunStateDto|null DTO состояния или null, если файла нет или JSON невалиден.
     */
    public static function read(string $sessionKey, string $agentName): ?RunStateDto
    {
        $path = self::filePath($sessionKey, $agentName);
        if (!file_exists($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return RunStateDto::fromArray($data);
    }

    /**
     * Записывает состояние run в файл чекпоинта атомарно.
     *
     * Сначала пишет во временный файл в той же директории, затем переименовывает в целевой.
     *
     * @param RunStateDto $state Состояние для сохранения.
     * @throws \JsonException При ошибке кодирования JSON.
     * @throws \RuntimeException При ошибке записи или rename.
     */
    public static function write(RunStateDto $state): void
    {
        $path = self::filePath($state->getSessionKey(), $state->getAgentName());
        $dir = dirname($path);
        $tmp = $dir . DIRECTORY_SEPARATOR . 'run_state_' . uniqid('', true) . '.tmp';
        $json = json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) === false) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            throw new \RuntimeException('Не удалось записать чекпоинт во временный файл: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось переименовать временный файл чекпоинта в: ' . $path);
        }
    }

    /**
     * Удаляет файл чекпоинта для указанной сессии и агента, если он существует.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $agentName  Имя агента.
     */
    public static function delete(string $sessionKey, string $agentName): void
    {
        $path = self::filePath($sessionKey, $agentName);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
