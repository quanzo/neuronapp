<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\storage;

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
use function strlen;
use function time;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Хранилище промежуточных результатов для одной директории `.store`.
 *
 * Инкапсулирует операции exists/save/load/list/delete, используя LLM-friendly
 * JSON-формат и индекс по sessionKey. Не зависит от ConfigurationApp,
 * получает путь к директории в конструкторе.
 */
final class IntermediateStorage
{
    public const SCHEMA_INTERMEDIATE_V1 = 'neuronapp.intermediate.v1';
    public const SCHEMA_INDEX_V1 = 'neuronapp.intermediate_index.v1';

    public function __construct(
        private readonly string $storeDir,
    ) {
    }

    /**
     * Формирует безопасное имя файла результата.
     */
    public function resultFileName(string $sessionKey, string $label): string
    {
        $safeKey = $this->sanitizeKeyPart($sessionKey);
        $safeLabel = $this->sanitizeKeyPart($label);
        return 'intermediate_' . $safeKey . '_' . $safeLabel . '.json';
    }

    private function resultFilePath(string $sessionKey, string $label): string
    {
        return $this->storeDir . DIRECTORY_SEPARATOR . $this->resultFileName($sessionKey, $label);
    }

    private function indexFileName(string $sessionKey): string
    {
        $safeKey = $this->sanitizeKeyPart($sessionKey);
        return 'intermediate_index_' . $safeKey . '.json';
    }

    private function indexFilePath(string $sessionKey): string
    {
        return $this->storeDir . DIRECTORY_SEPARATOR . $this->indexFileName($sessionKey);
    }

    public function exists(string $sessionKey, string $label): bool
    {
        return file_exists($this->resultFilePath($sessionKey, $label));
    }

    public function delete(string $sessionKey, string $label): void
    {
        $this->validateLabel($label);

        $path = $this->resultFilePath($sessionKey, $label);
        if (file_exists($path)) {
            @unlink($path);
        }

        $index = $this->readIndex($sessionKey);
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
        $this->atomicWrite($this->indexFilePath($sessionKey), $json);
    }

    /**
     * Сохраняет промежуточный результат и обновляет индекс.
     */
    public function save(string $sessionKey, string $label, mixed $data): IntermediateIndexItemDto
    {
        $this->validateLabel($label);

        $savedAt = date('c', time());
        $dataType = $this->detectDataType($data);

        $payload = [
            'schema' => self::SCHEMA_INTERMEDIATE_V1,
            'sessionKey' => $sessionKey,
            'label' => $label,
            'savedAt' => $savedAt,
            'dataType' => $dataType,
            'data' => $data,
        ];

        $path = $this->resultFilePath($sessionKey, $label);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->atomicWrite($path, $json);

        $sizeBytes = filesize($path);
        $item = new IntermediateIndexItemDto(
            label: $label,
            fileName: $this->resultFileName($sessionKey, $label),
            savedAt: $savedAt,
            dataType: $dataType,
            sizeBytes: is_int($sizeBytes) ? $sizeBytes : 0,
        );

        $this->upsertIndexItem($sessionKey, $item);

        return $item;
    }

    /**
     * Загружает ранее сохранённый результат.
     *
     * @return array{schema?: string, sessionKey?: string, label?: string, savedAt?: string, dataType?: string, data?: mixed}|null
     */
    public function load(string $sessionKey, string $label): ?array
    {
        $this->validateLabel($label);

        $path = $this->resultFilePath($sessionKey, $label);
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
     */
    public function list(string $sessionKey): array
    {
        $index = $this->readIndex($sessionKey);
        if ($index !== null) {
            return $index->items;
        }

        return $this->scanStoreForSession($sessionKey);
    }

    private function readIndex(string $sessionKey): ?IntermediateIndexDto
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

        return IntermediateIndexDto::tryFromArray($decoded);
    }

    private function upsertIndexItem(string $sessionKey, IntermediateIndexItemDto $item): void
    {
        $existing = $this->readIndex($sessionKey);
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
        $this->atomicWrite($this->indexFilePath($sessionKey), $json);
    }

    private function scanStoreForSession(string $sessionKey): array
    {
        $entries = @scandir($this->storeDir);
        if (!is_array($entries)) {
            return [];
        }

        $safeKey = $this->sanitizeKeyPart($sessionKey);
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

            $path = $this->storeDir . DIRECTORY_SEPARATOR . $entry;
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

            $savedAt = is_string($decoded['savedAt'] ?? null) ? (string) $decoded['savedAt'] : '';
            $dataType = is_string($decoded['dataType'] ?? null) ? (string) $decoded['dataType'] : '';
            $sizeBytes = filesize($path);

            $items[] = new IntermediateIndexItemDto(
                label: $label,
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

    private function sanitizeKeyPart(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $value);
        return is_string($safe) ? $safe : '';
    }

    private function validateLabel(string $label): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('label не может быть пустым.');
        }
        if (strlen($label) > 120) {
            throw new \InvalidArgumentException('label слишком длинный (максимум 120 символов).');
        }
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

