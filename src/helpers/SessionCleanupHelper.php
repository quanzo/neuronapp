<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\session\SessionCleanupResultDto;

use function basename;
use function file_exists;
use function glob;
use function is_array;
use function is_file;
use function preg_match;
use function preg_replace;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Хелпер очистки файлов, связанных с сессиями приложения.
 *
 * Под «сессией» в рамках удаления понимаются следующие файлы:
 * - `.sessions/neuron_<sessionKey>*.chat` (включая legacy-варианты с суффиксом `-agent`);
 * - `.store/run_state_<sessionKey>_*.json`;
 * - `.store/var_<sessionKey>_*.json` и `.store/var_index_<sessionKey>.json`;
 * - `.logs/<sessionKey>.log`.
 *
 * Хелпер предоставляет:
 * - сбор ключей сессий как union по `.sessions/.store/.logs`;
 * - вычисление списка файлов для конкретной сессии;
 * - удаление (или dry-run) с детальной статистикой.
 */
final class SessionCleanupHelper
{
    /**
     * Возвращает список всех sessionKey, найденных как union по `.sessions`, `.store`, `.logs`.
     *
     * @return string[] Уникальные sessionKey, отсортированные по возрастанию.
     */
    public static function listAllSessionKeysUnion(ConfigurationApp $appCfg): array
    {
        $keys = [];

        foreach (self::listSessionKeysFromSessionsDir($appCfg) as $k) {
            $keys[$k] = true;
        }
        foreach (self::listSessionKeysFromStoreDir($appCfg) as $k) {
            $keys[$k] = true;
        }
        foreach (self::listSessionKeysFromLogsDir($appCfg) as $k) {
            $keys[$k] = true;
        }

        $result = array_keys($keys);
        sort($result);
        return $result;
    }

    /**
     * Возвращает список файлов, которые относятся к указанному sessionKey.
     *
     * Важно: список может содержать пути к несуществующим файлам (для статистики missing).
     *
     * @return string[] Абсолютные пути.
     */
    public static function buildSessionFileCandidates(ConfigurationApp $appCfg, string $sessionKey): array
    {
        $result = [];

        $sessionsDir = $appCfg->getSessionDir();
        $storeDir    = $appCfg->getStoreDir();
        $logsDir     = $appCfg->getLogDir();

        // .sessions: neuron_<sessionKey>*.chat (включая legacy neuron_<sessionKey>-agent.chat)
        foreach (self::listChatFilesForSession($sessionsDir, $sessionKey) as $path) {
            $result[] = $path;
        }

        // .store: run_state_<safeKey>_*.json
        $safeKey = self::sanitizeKeyPart($sessionKey);
        $runStateGlob = $storeDir . DIRECTORY_SEPARATOR . 'run_state_' . $safeKey . '_*.json';
        foreach (glob($runStateGlob) ?: [] as $path) {
            $result[] = $path;
        }

        // .store: var_<safeKey>_*.json and var_index_<safeKey>.json
        $varGlob = $storeDir . DIRECTORY_SEPARATOR . 'var_' . $safeKey . '_*.json';
        foreach (glob($varGlob) ?: [] as $path) {
            $result[] = $path;
        }
        $result[] = $storeDir . DIRECTORY_SEPARATOR . 'var_index_' . $safeKey . '.json';

        // .logs: <sessionKey>.log
        $result[] = $logsDir . DIRECTORY_SEPARATOR . $sessionKey . '.log';

        // из директрии сессий уберем все файлы с сессионным ключом
        $arSessFiles = scandir($sessionsDir);
        $mask1 = '_' . $safeKey . '_';
        $mask2 = '_' . $safeKey . '.';
        foreach ($arSessFiles as $fn) {
            if (strpos($fn, $mask1) !== false || strpos($fn, $mask2) !== false) {
                $ffn = $sessionsDir . DIRECTORY_SEPARATOR . $fn;
                $result[] = $ffn;
            }
        }

        // Дедуп (на всякий случай)
        $uniq = [];
        foreach ($result as $p) {
            $uniq[$p] = true;
        }

        return array_keys($uniq);
    }

    /**
     * Выполняет очистку сессии: dry-run или реальное удаление.
     */
    public static function clearSession(ConfigurationApp $appCfg, string $sessionKey, bool $dryRun = false): SessionCleanupResultDto
    {
        $result = (new SessionCleanupResultDto())
            ->setSessionKey($sessionKey)
            ->setDryRun($dryRun);

        $paths = self::buildSessionFileCandidates($appCfg, $sessionKey);
        foreach ($paths as $path) {
            $result->addPlannedFile($path);

            if (!file_exists($path)) {
                $result->addMissingFile($path);
                continue;
            }

            if (!is_file($path)) {
                $result->addError('Не файл (пропущено): ' . $path);
                continue;
            }

            if ($dryRun) {
                continue;
            }

            if (@unlink($path)) {
                $result->addDeletedFile($path);
            } else {
                $result->addError('Не удалось удалить файл: ' . $path);
            }
        }

        return $result;
    }

    /**
     * Возвращает список sessionKey из `.sessions/neuron_*.chat`.
     *
     * @return string[]
     */
    private static function listSessionKeysFromSessionsDir(ConfigurationApp $appCfg): array
    {
        $dir = $appCfg->getSessionDir();
        $paths = glob($dir . DIRECTORY_SEPARATOR . 'neuron_*.chat') ?: [];

        $result = [];
        foreach ($paths as $path) {
            $base = basename($path);
            if (!str_starts_with($base, 'neuron_') || !str_ends_with($base, '.chat')) {
                continue;
            }

            $key = substr($base, 6, -5); // len('neuron_')=6, len('.chat')=5
            $key = ltrim($key, '_');
            if ($key === '') {
                continue;
            }
            $result[] = $key;
        }

        $result = array_values(array_unique($result));
        sort($result);
        return $result;
    }

    /**
     * Возвращает список sessionKey, найденных по именам файлов в `.store`.
     *
     * Замечание: имена в `.store` используют safeKey, который для "нормальных" sessionKey
     * совпадает с исходным. Для нестандартных ключей восстановление может быть неточным,
     * но это приемлемо для цели «подчистить хвосты».
     *
     * @return string[]
     */
    private static function listSessionKeysFromStoreDir(ConfigurationApp $appCfg): array
    {
        $dir = $appCfg->getStoreDir();

        $result = [];

        // run_state_<safeKey>_<agent>.json
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'run_state_*.json') ?: [] as $path) {
            $base = basename($path);
            if (preg_match('/^run_state_([^_]+)_.+\.json$/', $base, $m) === 1) {
                $safeKey = (string) ($m[1] ?? '');
                if ($safeKey !== '') {
                    $result[] = $safeKey;
                }
            }
        }

        // var_index_<safeKey>.json
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'var_index_*.json') ?: [] as $path) {
            $base = basename($path);
            if (preg_match('/^var_index_(.+)\.json$/', $base, $m) === 1) {
                $safeKey = (string) ($m[1] ?? '');
                if ($safeKey !== '') {
                    $result[] = $safeKey;
                }
            }
        }

        $result = array_values(array_unique($result));
        sort($result);
        return $result;
    }

    /**
     * Возвращает список sessionKey из `.logs/<sessionKey>.log`.
     *
     * @return string[]
     */
    private static function listSessionKeysFromLogsDir(ConfigurationApp $appCfg): array
    {
        $dir = $appCfg->getLogDir();
        $paths = glob($dir . DIRECTORY_SEPARATOR . '*.log') ?: [];

        $result = [];
        foreach ($paths as $path) {
            $base = basename($path);
            if (!str_ends_with($base, '.log')) {
                continue;
            }
            $key = substr($base, 0, -4); // len('.log')=4
            if ($key === '') {
                continue;
            }
            $result[] = $key;
        }

        $result = array_values(array_unique($result));
        sort($result);
        return $result;
    }

    /**
     * Возвращает список файлов истории чата `.sessions/neuron_<sessionKey>*.chat` для указанной сессии.
     *
     * Реализовано через сканирование маской, т.к. legacy-варианты могут иметь суффикс.
     *
     * @return string[]
     */
    private static function listChatFilesForSession(string $sessionsDir, string $sessionKey): array
    {
        $pattern = $sessionsDir . DIRECTORY_SEPARATOR . 'neuron_' . $sessionKey . '*.chat';
        $paths = glob($pattern);
        if (!is_array($paths) || $paths === []) {
            // Включаем базовый ожидаемый путь, чтобы корректно учитывать missing при статистике.
            return [
                $sessionsDir . DIRECTORY_SEPARATOR . 'neuron_' . $sessionKey . '.chat',
            ];
        }

        $result = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $result[] = $path;
            } else {
                $result[] = $path;
            }
        }
        $result = array_values(array_unique($result));
        sort($result);
        return $result;
    }

    /**
     * Нормализует часть ключа до безопасного имени файла.
     */
    private static function sanitizeKeyPart(string $value): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $value);
    }
}
