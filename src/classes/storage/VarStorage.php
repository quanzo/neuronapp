<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\storage;

use app\modules\neuron\classes\dto\tools\VarIndexDto;
use app\modules\neuron\classes\dto\tools\VarIndexItemDto;

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
 * Хранилище результатов для одной директории `.store`.
 */
final class VarStorage
{
    public const SCHEMA_VAR_V1 = 'neuronapp.var.v1';
    public const SCHEMA_INDEX_V1 = 'neuronapp.var_index.v1';

    private const RESULT_PREFIX = 'var_';
    private const INDEX_PREFIX = 'var_index_';
    private const TMP_PREFIX = 'var_';

    /**
     * @param string $storeDir Абсолютный путь к директории хранилища `.store`.
     */
    public function __construct(
        private readonly string $storeDir,
    ) {
    }

    /**
     * Формирует безопасное имя файла результата по паре (sessionKey, name).
     *
     * @return string Имя файла без пути (например, `var_20250101-120000-1_name.json`).
     */
    public function resultFileName(string $sessionKey, string $name): string
    {
        $safeKey = $this->sanitizeKeyPart($sessionKey);
        $safename = $this->sanitizeKeyPart($name);
        return self::RESULT_PREFIX . $safeKey . '_' . $safename . '.json';
    }

    private function resultFilePath(string $sessionKey, string $name): string
    {
        return $this->storeDir . DIRECTORY_SEPARATOR . $this->resultFileName($sessionKey, $name);
    }

    private function indexFileName(string $sessionKey): string
    {
        $safeKey = $this->sanitizeKeyPart($sessionKey);
        return self::INDEX_PREFIX . $safeKey . '.json';
    }

    private function indexFilePath(string $sessionKey): string
    {
        return $this->storeDir . DIRECTORY_SEPARATOR . $this->indexFileName($sessionKey);
    }

    public function exists(string $sessionKey, string $name): bool
    {
        return file_exists($this->resultFilePath($sessionKey, $name));
    }

    public function delete(string $sessionKey, string $name): void
    {
        $this->validateName($name);

        $path = $this->resultFilePath($sessionKey, $name);
        if (file_exists($path)) {
            @unlink($path);
        }

        $index = $this->readIndex($sessionKey);
        if ($index === null) {
            return;
        }

        $items = [];
        foreach ($index->items as $item) {
            if ($item->name === $name) {
                continue;
            }
            $items[] = $item;
        }

        $updated = new VarIndexDto(
            schema: self::SCHEMA_INDEX_V1,
            sessionKey: $sessionKey,
            items: $items,
        );

        $json = json_encode($updated->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->atomicWrite($this->indexFilePath($sessionKey), $json);
    }

    public function save(string $sessionKey, string $name, mixed $data, ?string $description = null): VarIndexItemDto
    {
        $this->validateName($name);
        $descriptionNorm = $this->normalizeDescription($description);

        $savedAt = date('c', time());
        $dataType = $this->detectDataType($data);

        $payload = [
            'schema' => self::SCHEMA_VAR_V1,
            'sessionKey' => $sessionKey,
            'name' => $name,
            'description' => $descriptionNorm,
            'savedAt' => $savedAt,
            'dataType' => $dataType,
            'data' => $data,
        ];

        $path = $this->resultFilePath($sessionKey, $name);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->atomicWrite($path, $json);

        $sizeBytes = filesize($path);
        $item = new VarIndexItemDto(
            name: $name,
            description: $descriptionNorm,
            fileName: $this->resultFileName($sessionKey, $name),
            savedAt: $savedAt,
            dataType: $dataType,
            sizeBytes: is_int($sizeBytes) ? $sizeBytes : 0,
        );

        $this->upsertIndexItem($sessionKey, $item);

        return $item;
    }

    /**
     * @return array{schema?: string, sessionKey?: string, name?: string, description?: string, savedAt?: string, dataType?: string, data?: mixed}|null
     */
    public function load(string $sessionKey, string $name): ?array
    {
        $this->validateName($name);

        $path = $this->resultFilePath($sessionKey, $name);
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
     * @return VarIndexItemDto[]
     */
    public function list(string $sessionKey): array
    {
        $index = $this->readIndex($sessionKey);
        if ($index !== null) {
            return $index->items;
        }

        return $this->scanStoreForSession($sessionKey);
    }

    private function readIndex(string $sessionKey): ?VarIndexDto
    {
        $path = $this->indexFilePath($sessionKey);
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

        return VarIndexDto::tryFromArray($decoded);
    }

    private function upsertIndexItem(string $sessionKey, VarIndexItemDto $item): void
    {
        $existing = $this->readIndex($sessionKey);
        $items = $existing?->items ?? [];

        $updated = [];
        $found = false;
        foreach ($items as $it) {
            if ($it->name === $item->name) {
                $updated[] = $item;
                $found = true;
                continue;
            }
            $updated[] = $it;
        }
        if (!$found) {
            $updated[] = $item;
        }

        $index = new VarIndexDto(
            schema: self::SCHEMA_INDEX_V1,
            sessionKey: $sessionKey,
            items: $updated,
        );

        $json = json_encode($index->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->atomicWrite($this->indexFilePath($sessionKey), $json);
    }

    /**
     * @return VarIndexItemDto[]
     */
    private function scanStoreForSession(string $sessionKey): array
    {
        $entries = @scandir($this->storeDir);
        if (!is_array($entries)) {
            return [];
        }

        $safeKey = $this->sanitizeKeyPart($sessionKey);
        $prefix = self::RESULT_PREFIX . $safeKey . '_';
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

            $path = $this->storeDir . DIRECTORY_SEPARATOR . $entry;
            $raw = file_get_contents($path);
            if (!is_string($raw)) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $name = is_string($decoded['name'] ?? null) ? (string) $decoded['name'] : '';
            if ($name === '') {
                continue;
            }

            $description = is_string($decoded['description'] ?? null) ? (string) $decoded['description'] : '';
            $savedAt = is_string($decoded['savedAt'] ?? null) ? (string) $decoded['savedAt'] : '';
            $dataType = is_string($decoded['dataType'] ?? null) ? (string) $decoded['dataType'] : '';
            $sizeBytes = filesize($path);

            $items[] = new VarIndexItemDto(
                name: $name,
                description: $description,
                fileName: $entry,
                savedAt: $savedAt,
                dataType: $dataType,
                sizeBytes: is_int($sizeBytes) ? $sizeBytes : 0,
            );
        }

        return $items;
    }

    private function atomicWrite(string $targetPath, string $content): void
    {
        $dir = dirname($targetPath);
        $tmp = $dir . DIRECTORY_SEPARATOR . self::TMP_PREFIX . uniqid('', true) . '.tmp';

        if (file_put_contents($tmp, $content) === false) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            throw new \RuntimeException('Не удалось записать результат во временный файл: ' . $tmp);
        }

        if (!rename($tmp, $targetPath)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось переименовать временный файл результата в: ' . $targetPath);
        }
    }

    private function sanitizeKeyPart(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $value);
        return is_string($safe) ? $safe : '';
    }

    private function validateName(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name не может быть пустым.');
        }
        if (strlen($name) > 120) {
            throw new \InvalidArgumentException('name слишком длинный (максимум 120 символов).');
        }
    }

    private function normalizeDescription(?string $description): string
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

    private function detectDataType(mixed $data): string
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
