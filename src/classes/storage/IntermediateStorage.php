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
use function substr;
use function strlen;
use function time;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Хранилище промежуточных результатов для одной директории `.store`.
 *
 * Этот класс инкапсулирует работу с файлами промежуточных результатов для
 * конкретной директории хранилища (обычно `.store`, путь к которой отдаёт
 * {@see \app\modules\neuron\classes\config\ConfigurationApp::getStoreDir()}).
 *
 * Основные возможности:
 * - формирование имён файлов по паре (sessionKey, label);
 * - сохранение результата в LLM-friendly JSON-формате (envelope с метаданными);
 * - загрузка результата по метке;
 * - проверка существования, удаление и перечисление результатов для сессии;
 * - поддержка индекс-файла для ускорения операций list().
 *
 * Формат файла результата:
 * - `schema`      — версия схемы (`neuronapp.intermediate.v1`);
 * - `sessionKey`  — ключ сессии;
 * - `label`       — метка результата;
 * - `description` — краткое описание результата (для list и понимания LLM);
 * - `savedAt`     — время сохранения в ISO‑8601;
 * - `dataType`    — тип данных (`string|object|array|number|boolean|null`);
 * - `data`        — произвольное JSON-совместимое значение.
 *
 * Формат индекс-файла:
 * - `schema`      — версия схемы (`neuronapp.intermediate_index.v1`);
 * - `sessionKey`  — ключ сессии;
 * - `items`       — массив {@see IntermediateIndexItemDto::toArray()}.
 *
 * Пример использования:
 *
 * ```php
 * use app\modules\neuron\classes\storage\IntermediateStorage;
 *
 * $storage = new IntermediateStorage(__DIR__ . '/.store');
 * $sessionKey = '20250101-120000-1';
 *
 * // Сохранить результат
 * $item = $storage->save($sessionKey, 'plan', ['steps' => ['a', 'b']]);
 *
 * // Проверить существование
 * if ($storage->exists($sessionKey, 'plan')) {
 *     // Загрузить содержимое
 *     $data = $storage->load($sessionKey, 'plan');
 * }
 *
 * // Получить список всех результатов этой сессии
 * $items = $storage->list($sessionKey);
 *
 * // Удалить результат
 * $storage->delete($sessionKey, 'plan');
 * ```
 */
final class IntermediateStorage
{
    public const SCHEMA_INTERMEDIATE_V1 = 'neuronapp.intermediate.v1';
    public const SCHEMA_INDEX_V1 = 'neuronapp.intermediate_index.v1';

    /**
     * @param string $storeDir Абсолютный путь к директории хранилища `.store`.
     */
    public function __construct(
        private readonly string $storeDir,
    ) {
    }

    /**
     * Формирует безопасное имя файла результата по паре (sessionKey, label).
     *
     * В имени файла используются только символы [a-zA-Z0-9_-]; все остальные
     * символы в ключе сессии и метке заменяются на подчёркивание. Расширение
     * всегда `.json`.
     *
     * @param string $sessionKey Базовый ключ сессии (без имени агента).
     * @param string $label      Метка результата.
     *
     * @return string Имя файла без пути (например, `intermediate_20250101-120000-1_label.json`).
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

    /**
     * Проверяет, существует ли файл результата для переданных sessionKey и label.
     *
     * Метод не валидирует содержимое файла, только факт его наличия.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата.
     *
     * @return bool true, если файл существует в директории хранилища.
     */
    public function exists(string $sessionKey, string $label): bool
    {
        return file_exists($this->resultFilePath($sessionKey, $label));
    }

    /**
     * Удаляет результат и синхронизирует индекс для заданной пары (sessionKey, label).
     *
     * Поведение идемпотентно: если файла результата или записи в индексе нет,
     * метод завершится без исключения. Если индекс существует, из него
     * удаляется элемент с соответствующей меткой.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата.
     *
     * @return void
     */
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
     *
     * Данные сохраняются в JSON-файл с LLM-friendly конвертом (см. описание
     * класса). Индекс-файл дополняется или обновляет существующую запись
     * для переданной метки.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата (валидируется; не может быть пустой/слишком длинной).
     * @param mixed       $data        JSON-совместимое значение для записи.
     * @param string|null $description Краткое описание результата (рекомендуется для list).
     *
     * @return IntermediateIndexItemDto DTO с метаданными сохранённого результата.
     *
     * @throws \InvalidArgumentException Если label некорректен.
     * @throws \JsonException            При ошибке кодирования JSON.
     * @throws \RuntimeException         При ошибке записи/переименования файла.
     */
    public function save(string $sessionKey, string $label, mixed $data, ?string $description = null): IntermediateIndexItemDto
    {
        $this->validateLabel($label);
        $descriptionNorm = $this->normalizeDescription($description);

        $savedAt = date('c', time());
        $dataType = $this->detectDataType($data);

        $payload = [
            'schema' => self::SCHEMA_INTERMEDIATE_V1,
            'sessionKey' => $sessionKey,
            'label' => $label,
            'description' => $descriptionNorm,
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
            description: $descriptionNorm,
            fileName: $this->resultFileName($sessionKey, $label),
            savedAt: $savedAt,
            dataType: $dataType,
            sizeBytes: is_int($sizeBytes) ? $sizeBytes : 0,
        );

        $this->upsertIndexItem($sessionKey, $item);

        return $item;
    }

    /**
     * Загружает ранее сохранённый результат по паре (sessionKey, label).
     *
     * При отсутствии файла или при некорректном/неожиданном содержимом возвращает null
     * без выброса исключения.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $label      Метка результата (валидируется).
     *
     * @return array{schema?: string, sessionKey?: string, label?: string, description?: string, savedAt?: string, dataType?: string, data?: mixed}|null
     *
     * @throws \InvalidArgumentException Если label некорректен.
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
     * Возвращает список всех сохранённых результатов для указанного sessionKey.
     *
     * Приоритет отдаётся индекс-файлу. Если индекс отсутствует или повреждён,
     * выполняется прямое сканирование директории `.store` по префиксу файлов
     * этой сессии.
     *
     * @param string $sessionKey Базовый ключ сессии.
     *
     * @return IntermediateIndexItemDto[] Массив DTO с метаданными результатов.
     */
    public function list(string $sessionKey): array
    {
        $index = $this->readIndex($sessionKey);
        if ($index !== null) {
            return $index->items;
        }

        return $this->scanStoreForSession($sessionKey);
    }

    /**
     * Читает индекс для заданного sessionKey.
     *
     * @param string $sessionKey Базовый ключ сессии.
     *
     * @return IntermediateIndexDto|null Объект индекса или null, если файла нет или он некорректен.
     */
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

    /**
     * Добавляет или обновляет элемент индекса и записывает индекс-файл атомарно.
     *
     * @param string                  $sessionKey Базовый ключ сессии.
     * @param IntermediateIndexItemDto $item      Элемент индекса (метаданные результата).
     *
     * @return void
     *
     * @throws \JsonException    При ошибке кодирования JSON.
     * @throws \RuntimeException При ошибке записи/переименования индекс-файла.
     */
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

    /**
     * Выполняет полное сканирование директории хранилища для данной сессии.
     *
     * Используется как fallback, когда индекс-файл отсутствует или повреждён.
     * Ищет файлы формата `intermediate_{sessionKey}_*.json` и пытается
     * восстановить метаданные из их содержимого.
     *
     * @param string $sessionKey Базовый ключ сессии.
     *
     * @return IntermediateIndexItemDto[] Массив DTO на основе найденных файлов.
     */
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
     * Атомарно записывает содержимое в файл.
     *
     * Запись производится во временный файл в той же директории, после чего
     * выполняется `rename()` в целевой путь. Это защищает от появления
     * битых файлов при сбое в процессе записи.
     *
     * @param string $targetPath Путь к целевому файлу.
     * @param string $content    Содержимое для записи.
     *
     * @return void
     *
     * @throws \RuntimeException При ошибке записи или переименования.
     */
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

    /**
     * Делает строку безопасной для использования в имени файла.
     *
     * Разрешены только символы `[a-zA-Z0-9_-]`, остальные заменяются
     * на символ подчёркивания.
     *
     * @param string $value Исходное значение.
     *
     * @return string Безопасная строка для имени файла.
     */
    private function sanitizeKeyPart(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $value);
        return is_string($safe) ? $safe : '';
    }

    /**
     * Валидирует метку результата.
     *
     * Правила:
     * - метка не может быть пустой (после trim);
     * - длина метки не должна превышать 120 символов.
     *
     * @param string $label Метка для проверки.
     *
     * @return void
     *
     * @throws \InvalidArgumentException При нарушении правил.
     */
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

    /**
     * Нормализует описание результата.
     *
     * Требование: описание должно быть кратким. Здесь:
     * - null приводится к пустой строке;
     * - выполняется trim;
     * - строка обрезается до 200 символов.
     *
     * @param string|null $description Описание от вызывающего кода.
     *
     * @return string Нормализованное описание (может быть пустым).
     */
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

    /**
     * Определяет тип сохраняемых данных для поля `dataType`.
     *
     * @param mixed $data Произвольное значение.
     *
     * @return string Одно из: `string|object|array|number|boolean|null`.
     */
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
