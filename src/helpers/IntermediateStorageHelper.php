<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\tools\IntermediateIndexDto;
use app\modules\neuron\classes\dto\tools\IntermediateIndexItemDto;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_replace;
use function rename;
use function scandir;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function strlen;
use function time;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Хелпер для хранения промежуточных результатов инструментов в `.store`.
 *
 * Назначение: сохранять/загружать/перечислять промежуточные результаты,
 * привязанные к текущему sessionKey, в LLM-дружелюбном JSON-формате.
 *
 * Формат файлов:
 * - `.store/intermediate_{sessionKey}_{label}.json` — один результат;
 * - `.store/intermediate_index_{sessionKey}.json` — индекс результатов для list().
 *
 * Запись выполняется атомарно (tmp + rename) для защиты от битых файлов.
 */
final class IntermediateStorageHelper
{
    public const SCHEMA_INTERMEDIATE_V1 = 'neuronapp.intermediate.v1';
    public const SCHEMA_INDEX_V1 = 'neuronapp.intermediate_index.v1';

    /**
     * Формирует безопасное имя файла результата.
     *
     * Недопустимые для файловой системы символы заменяются на подчёркивание.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата.
     * @return string Имя файла результата без пути.
     */
    public static function resultFileName(string $sessionKey, string $label): string
    {
        $safeKey = self::sanitizeKeyPart($sessionKey);
        $safeLabel = self::sanitizeKeyPart($label);
        return 'intermediate_' . $safeKey . '_' . $safeLabel . '.json';
    }

    /**
     * Возвращает полный путь к файлу результата.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата.
     * @return string Абсолютный путь к файлу результата.
     */
    public static function resultFilePath(string $sessionKey, string $label): string
    {
        $storeDir = ConfigurationApp::getInstance()->getStoreDir();
        return $storeDir . DIRECTORY_SEPARATOR . self::resultFileName($sessionKey, $label);
    }

    /**
     * Формирует безопасное имя индекс-файла для sessionKey.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @return string Имя индекс-файла без пути.
     */
    public static function indexFileName(string $sessionKey): string
    {
        $safeKey = self::sanitizeKeyPart($sessionKey);
        return 'intermediate_index_' . $safeKey . '.json';
    }

    /**
     * Возвращает полный путь к индекс-файлу для sessionKey.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @return string Абсолютный путь к индекс-файлу.
     */
    public static function indexFilePath(string $sessionKey): string
    {
        $storeDir = ConfigurationApp::getInstance()->getStoreDir();
        return $storeDir . DIRECTORY_SEPARATOR . self::indexFileName($sessionKey);
    }

    /**
     * Проверяет наличие файла результата.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата.
     * @return bool true, если файл существует.
     */
    public static function exists(string $sessionKey, string $label): bool
    {
        return file_exists(self::resultFilePath($sessionKey, $label));
    }

    /**
     * Удаляет промежуточный результат и обновляет индекс (если он есть).
     *
     * Операция считается успешной, даже если файла не было (idempotent).
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата.
     */
    public static function delete(string $sessionKey, string $label): void
    {
        self::validateLabel($label);

        $path = self::resultFilePath($sessionKey, $label);
        if (file_exists($path)) {
            @unlink($path);
        }

        $index = self::readIndex($sessionKey);
        if ($index === null) {
            return;
        }

        $items = [];
        foreach ($index->items as $item) {
            if ($item->label === $label) {
                continue;
            }
            $items[] = $item;
        }

        $updated = new IntermediateIndexDto(
            schema: self::SCHEMA_INDEX_V1,
            sessionKey: $sessionKey,
            items: $items,
        );

        $json = json_encode($updated->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::atomicWrite(self::indexFilePath($sessionKey), $json);
    }

    /**
     * Сохраняет промежуточный результат в `.store` и обновляет индекс.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата.
     * @param mixed       $data        Данные (JSON-совместимое значение).
     * @param string|null $description Краткое описание результата.
     * @return IntermediateIndexItemDto Метаданные сохранённого результата.
     * @throws \JsonException При ошибке кодирования JSON.
     * @throws \RuntimeException При ошибке записи.
     */
    public static function save(string $sessionKey, string $label, mixed $data, ?string $description = null): IntermediateIndexItemDto
    {
        self::validateLabel($label);

        $savedAt = date('c', time());
        $dataType = self::detectDataType($data);
        $descriptionNorm = self::normalizeDescription($description);

        $payload = [
            'schema' => self::SCHEMA_INTERMEDIATE_V1,
            'sessionKey' => $sessionKey,
            'label' => $label,
            'description' => $descriptionNorm,
            'savedAt' => $savedAt,
            'dataType' => $dataType,
            'data' => $data,
        ];

        $path = self::resultFilePath($sessionKey, $label);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::atomicWrite($path, $json);

        $sizeBytes = filesize($path);
        $item = new IntermediateIndexItemDto(
            label: $label,
            description: $descriptionNorm,
            fileName: self::resultFileName($sessionKey, $label),
            savedAt: $savedAt,
            dataType: $dataType,
            sizeBytes: is_int($sizeBytes) ? $sizeBytes : 0,
        );

        self::upsertIndexItem($sessionKey, $item);

        return $item;
    }

    /**
     * Загружает ранее сохранённый результат.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата.
     * @return array{schema?: string, sessionKey?: string, label?: string, savedAt?: string, dataType?: string, data?: mixed}|null
     */
    public static function load(string $sessionKey, string $label): ?array
    {
        self::validateLabel($label);

        $path = self::resultFilePath($sessionKey, $label);
        if (!file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Возвращает список сохранённых результатов для sessionKey.
     *
     * Приоритет: индекс-файл. Если индекс отсутствует/битый — выполняется
     * мягкое восстановление сканированием `.store` по префиксу.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @return IntermediateIndexItemDto[]
     */
    public static function list(string $sessionKey): array
    {
        $index = self::readIndex($sessionKey);
        if ($index !== null) {
            return $index->items;
        }

        return self::scanStoreForSession($sessionKey);
    }

    /**
     * Читает индекс результатов.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @return IntermediateIndexDto|null Индекс или null при отсутствии/ошибке.
     */
    private static function readIndex(string $sessionKey): ?IntermediateIndexDto
    {
        $path = self::indexFilePath($sessionKey);
        if (!file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return IntermediateIndexDto::tryFromArray($decoded);
    }

    /**
     * Добавляет или обновляет элемент индекса и записывает индекс атомарно.
     *
     * @param string                  $sessionKey Базовый ключ сессии.
     * @param IntermediateIndexItemDto $item       Элемент для вставки/обновления.
     * @throws \JsonException При ошибке кодирования JSON.
     */
    private static function upsertIndexItem(string $sessionKey, IntermediateIndexItemDto $item): void
    {
        $existing = self::readIndex($sessionKey);
        $items = $existing?->items ?? [];

        $updated = [];
        $found = false;
        foreach ($items as $it) {
            if ($it->label === $item->label) {
                $updated[] = $item;
                $found = true;
                continue;
            }
            $updated[] = $it;
        }
        if (!$found) {
            $updated[] = $item;
        }

        $index = new IntermediateIndexDto(
            schema: self::SCHEMA_INDEX_V1,
            sessionKey: $sessionKey,
            items: $updated,
        );

        $json = json_encode($index->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::atomicWrite(self::indexFilePath($sessionKey), $json);
    }

    /**
     * Сканирует `.store` на наличие файлов промежуточных результатов для sessionKey.
     *
     * Это fallback, если индекс отсутствует/повреждён.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @return IntermediateIndexItemDto[]
     */
    private static function scanStoreForSession(string $sessionKey): array
    {
        $storeDir = ConfigurationApp::getInstance()->getStoreDir();
        $entries = @scandir($storeDir);
        if (!is_array($entries)) {
            return [];
        }

        $safeKey = self::sanitizeKeyPart($sessionKey);
        $prefix = 'intermediate_' . $safeKey . '_';
        $items = [];

        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            if (!str_starts_with($entry, $prefix)) {
                continue;
            }
            if (!str_ends_with($entry, '.json')) {
                continue;
            }

            $path = $storeDir . DIRECTORY_SEPARATOR . $entry;
            $raw = file_get_contents($path);
            if (!is_string($raw)) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $label = is_string($decoded['label'] ?? null) ? (string) $decoded['label'] : '';
            if ($label === '') {
                continue;
            }

            $description = is_string($decoded['description'] ?? null) ? (string) $decoded['description'] : '';
            $savedAt = is_string($decoded['savedAt'] ?? null) ? (string) $decoded['savedAt'] : '';
            $dataType = is_string($decoded['dataType'] ?? null) ? (string) $decoded['dataType'] : '';
            $sizeBytes = filesize($path);

            $items[] = new IntermediateIndexItemDto(
                label: $label,
                description: $description,
                fileName: $entry,
                savedAt: $savedAt,
                dataType: $dataType,
                sizeBytes: is_int($sizeBytes) ? $sizeBytes : 0,
            );
        }

        return $items;
    }

    /**
     * Атомарная запись в файл через временный файл и rename().
     *
     * @param string $targetPath Путь к целевому файлу.
     * @param string $content    Содержимое для записи.
     * @throws \RuntimeException При ошибке записи или переименования.
     */
    private static function atomicWrite(string $targetPath, string $content): void
    {
        $dir = dirname($targetPath);
        $tmp = $dir . DIRECTORY_SEPARATOR . 'intermediate_' . uniqid('', true) . '.tmp';

        if (file_put_contents($tmp, $content) === false) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            throw new \RuntimeException('Не удалось записать промежуточный результат во временный файл: ' . $tmp);
        }

        if (!rename($tmp, $targetPath)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось переименовать временный файл промежуточного результата в: ' . $targetPath);
        }
    }

    /**
     * Делает часть ключа безопасной для имени файла.
     *
     * @param string $value Исходное значение.
     * @return string Безопасная строка (a-zA-Z0-9_-).
     */
    private static function sanitizeKeyPart(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $value);
        return is_string($safe) ? $safe : '';
    }

    /**
     * Валидирует метку результата.
     *
     * @param string $label Метка (не пустая, разумной длины).
     * @throws \InvalidArgumentException Если метка некорректна.
     */
    private static function validateLabel(string $label): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('label не может быть пустым.');
        }
        if (strlen($label) > 120) {
            throw new \InvalidArgumentException('label слишком длинный (максимум 120 символов).');
        }
    }

    /**
     * Нормализует описание результата (краткое).
     *
     * - null -> ''
     * - trim
     * - ограничение длины 200 символов
     */
    private static function normalizeDescription(?string $description): string
    {
        $description = trim((string) ($description ?? ''));
        if ($description === '') {
            return '';
        }
        if (strlen($description) > 200) {
            return substr($description, 0, 200);
        }
        return $description;
    }

    /**
     * Определяет тип данных для метаданных.
     *
     * @param mixed $data Данные.
     * @return string string|object|array|number|boolean|null
     */
    private static function detectDataType(mixed $data): string
    {
        if ($data === null) {
            return 'null';
        }
        if (is_string($data)) {
            return 'string';
        }
        if (is_int($data) || is_float($data)) {
            return 'number';
        }
        if (is_bool($data)) {
            return 'boolean';
        }
        if (is_array($data)) {
            return 'array';
        }
        return 'object';
    }
}
