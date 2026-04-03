<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\storage;

use app\modules\neuron\classes\dto\tools\VarIndexDto;
use app\modules\neuron\classes\dto\tools\VarIndexItemDto;
use app\modules\neuron\helpers\JsonHelper;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function is_array;
use function is_int;
use function is_string;
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

/**
 * VarStorage — файловое хранилище переменных (результатов) в директории `.store`.
 *
 * Класс отвечает за сохранение/загрузку/удаление и перечисление значений,
 * привязанных к конкретной сессии (`sessionKey`) и имени переменной (`name`).
 *
 * ### Расположение файлов
 *
 * - **Результаты**: `.store/var_{sessionKey}_{name}.json`
 * - **Индекс** (ускоряет list): `.store/var_index_{sessionKey}.json`
 *
 * В `sessionKey` и `name` допускаются любые символы, но для имени файла они
 * нормализуются в безопасный вид (см. {@see sanitizeKeyPart()}).
 *
 * ### Формат файла результата (JSON)
 *
 * ```json
 * {
 *   "schema": "neuronapp.var.v1",
 *   "sessionKey": "20250101-120000-1",
 *   "name": "counter",
 *   "description": "Текущее значение",
 *   "savedAt": "2026-03-25T12:34:56+00:00",
 *   "dataType": "number",
 *   "data": 1
 * }
 * ```
 *
 * ### Формат индекс-файла (JSON)
 *
 * ```json
 * {
 *   "schema": "neuronapp.var_index.v1",
 *   "sessionKey": "20250101-120000-1",
 *   "items": [ { "name": "counter", "description": "...", "fileName": "...", "savedAt": "...", "dataType": "...", "sizeBytes": 123 } ]
 * }
 * ```
 *
 * ### Пример использования
 *
 * ```php
 * use app\modules\neuron\classes\storage\VarStorage;
 *
 * $storage = new VarStorage(__DIR__ . '/.store');
 * $sessionKey = '20250101-120000-1';
 *
 * // Установить переменную
 * $storage->save($sessionKey, 'counter', 1, 'Текущий номер шага');
 *
 * // Получить переменную
 * $payload = $storage->load($sessionKey, 'counter');
 * $value = $payload['data'] ?? null;
 *
 * // Проверить существование и удалить
 * if ($storage->exists($sessionKey, 'counter')) {
 *     $storage->delete($sessionKey, 'counter');
 * }
 * ```
 */
final class VarStorage
{
    public const SCHEMA_VAR_V1 = 'neuronapp.var.v1';
    public const SCHEMA_INDEX_V1 = 'neuronapp.var_index.v1';

    private const RESULT_PREFIX = 'var_';
    private const INDEX_PREFIX = 'var_index_';
    private const TMP_PREFIX = 'var_';

    /**
     * Создаёт экземпляр хранилища для указанной директории `.store`.
     *
     * @param string $storeDir Абсолютный путь к директории `.store`.
     */
    public function __construct(
        private readonly string $storeDir,
    ) {
    }

    /**
     * Формирует безопасное имя файла результата по паре (sessionKey, name).
     *
     * Используется только имя файла (без пути). Безопасность обеспечивается
     * заменой недопустимых символов на `_` (см. {@see sanitizeKeyPart()}).
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $name       Имя переменной.
     *
     * @return string Имя файла без пути (например, `var_20250101-120000-1_name.json`).
     */
    public function resultFileName(string $sessionKey, string $name): string
    {
        $safeKey = $this->sanitizeKeyPart($sessionKey);
        $safename = $this->sanitizeKeyPart($name);
        return self::RESULT_PREFIX . $safeKey . '_' . $safename . '.json';
    }

    /**
     * Формирует полный путь до файла результата в `.store`.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $name       Имя переменной.
     *
     * @return string Абсолютный путь к файлу результата.
     */
    private function resultFilePath(string $sessionKey, string $name): string
    {
        return $this->storeDir . DIRECTORY_SEPARATOR . $this->resultFileName($sessionKey, $name);
    }

    /**
     * Формирует имя индекс-файла для заданной сессии.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @return string Имя индекс-файла без пути.
     */
    private function indexFileName(string $sessionKey): string
    {
        $safeKey = $this->sanitizeKeyPart($sessionKey);
        return self::INDEX_PREFIX . $safeKey . '.json';
    }

    /**
     * Формирует полный путь до индекс-файла в `.store`.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @return string Абсолютный путь к индекс-файлу.
     */
    private function indexFilePath(string $sessionKey): string
    {
        return $this->storeDir . DIRECTORY_SEPARATOR . $this->indexFileName($sessionKey);
    }

    /**
     * Проверяет наличие сохранённой переменной по имени.
     *
     * В отличие от {@see load()}, метод не читает содержимое и не валидирует JSON —
     * он проверяет только существование файла результата.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $name       Имя переменной.
     *
     * @return bool true, если файл результата существует.
     */
    public function exists(string $sessionKey, string $name): bool
    {
        return file_exists($this->resultFilePath($sessionKey, $name));
    }

    /**
     * Удаляет переменную по имени и синхронизирует индекс (если он существует).
     *
     * Поведение идемпотентно: отсутствие файла результата не считается ошибкой.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $name       Имя переменной.
     *
     * @return void
     *
     * @throws \InvalidArgumentException Если имя переменной некорректно.
     * @throws \JsonException            При ошибке кодирования индекс-файла.
     * @throws \RuntimeException         При ошибке атомарной записи индекс-файла.
     */
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

        $json = JsonHelper::encodeThrow($updated->toArray());
        $this->atomicWrite($this->indexFilePath($sessionKey), $json);
    }

    /**
     * Сохраняет переменную по имени (создаёт или перезаписывает) и обновляет индекс.
     *
     * Метод записывает файл результата в JSON-формате и добавляет/обновляет
     * соответствующую запись в индекс-файле.
     *
     * @param string      $sessionKey   Базовый ключ сессии.
     * @param string      $name         Имя переменной.
     * @param mixed       $data         JSON-совместимое значение.
     * @param string|null $description  Краткое описание (для list).
     *
     * @return VarIndexItemDto Метаданные сохранённой переменной (для индекса и ответа инструментов).
     *
     * @throws \InvalidArgumentException Если имя переменной некорректно.
     * @throws \JsonException            При ошибке кодирования JSON результата или индекса.
     * @throws \RuntimeException         При ошибке атомарной записи результата/индекса.
     */
    public function save(string $sessionKey, string $name, mixed $data, ?string $description = null): VarIndexItemDto
    {
        $this->validateName($name);
        $descriptionNorm = $this->normalizeDescription($description);

        $savedAt = date('c', time());
        $dataType = $this->detectDataType($data);

        $payload = [
            'schema'      => self::SCHEMA_VAR_V1,
            'sessionKey'  => $sessionKey,
            'name'        => $name,
            'description' => $descriptionNorm,
            'savedAt'     => $savedAt,
            'dataType'    => $dataType,
            'data'        => $data,
        ];

        $path = $this->resultFilePath($sessionKey, $name);

        $json = JsonHelper::encodeUnicodeWithUtf8Fallback($payload);

        $this->atomicWrite($path, $json);

        $sizeBytes = filesize($path);
        $item = new VarIndexItemDto(
            name       : $name,
            description: $descriptionNorm,
            fileName   : $this->resultFileName($sessionKey, $name),
            savedAt    : $savedAt,
            dataType   : $dataType,
            sizeBytes  : is_int($sizeBytes) ? $sizeBytes          : 0,
        );

        $this->upsertIndexItem($sessionKey, $item);

        return $item;
    }

    /**
     * Загружает сохранённую переменную по имени.
     *
     * Метод возвращает содержимое JSON-файла "как есть" (ассоциативный массив),
     * без строгой схемной валидации. Если файл отсутствует или содержимое не читается —
     * возвращает null.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @param string $name       Имя переменной.
     *
     * @return array{schema?: string, sessionKey?: string, name?: string, description?: string, savedAt?: string, dataType?: string, data?: mixed}|null
     *
     * @throws \InvalidArgumentException Если имя переменной некорректно.
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

        $decoded = JsonHelper::tryDecodeAssociativeArray($raw);
        if ($decoded === null) {
            return null;
        }

        return $decoded;
    }

    /**
     * Возвращает список сохранённых переменных для заданной сессии.
     *
     * Предпочитает индекс-файл (если он есть и читается). Если индекс отсутствует
     * или поврежден, выполняет "fallback" — сканирование директории `.store`.
     *
     * @param string $sessionKey Базовый ключ сессии.
     *
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

    /**
     * Читает индекс-файл и пытается восстановить DTO.
     *
     * @param string $sessionKey Базовый ключ сессии.
     * @return VarIndexDto|null DTO индекса или null, если файла нет/он некорректен.
     */
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

        $decoded = JsonHelper::tryDecodeAssociativeArray($raw);
        if ($decoded === null) {
            return null;
        }

        return VarIndexDto::tryFromArray($decoded);
    }

    /**
     * Добавляет или обновляет элемент индекса и записывает индекс-файл.
     *
     * @param string         $sessionKey Базовый ключ сессии.
     * @param VarIndexItemDto $item      Элемент индекса.
     *
     * @return void
     *
     * @throws \JsonException    При ошибке кодирования JSON индекс-файла.
     * @throws \RuntimeException При ошибке атомарной записи индекс-файла.
     */
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

        $json = JsonHelper::encodeThrow($index->toArray());
        $this->atomicWrite($this->indexFilePath($sessionKey), $json);
    }

    /**
     * Выполняет сканирование `.store` и восстанавливает индекс по файлам результата.
     *
     * Используется как fallback, когда индекс-файл отсутствует или поврежден.
     * Метод читает каждый найденный файл результата и пытается извлечь метаданные
     * (`name`, `description`, `savedAt`, `dataType`) для построения списка.
     *
     * @param string $sessionKey Базовый ключ сессии.
     *
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
            $decoded = JsonHelper::tryDecodeAssociativeArray($raw);
            if ($decoded === null) {
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

    /**
     * Атомарно записывает содержимое в целевой файл.
     *
     * Записывает данные во временный файл в той же директории, затем делает `rename()`.
     * Такой подход снижает риск получить частично записанный файл при сбоях.
     *
     * @param string $targetPath Путь к целевому файлу.
     * @param string $content    Содержимое для записи.
     *
     * @return void
     *
     * @throws \RuntimeException Если не удалось записать или переименовать файл.
     */
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

    /**
     * Нормализует часть ключа для безопасного использования в имени файла.
     *
     * Разрешены только символы `[a-zA-Z0-9_-]`, остальные заменяются на `_`.
     *
     * @param string $value Исходная строка.
     * @return string Безопасная строка.
     */
    private function sanitizeKeyPart(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $value);
        return is_string($safe) ? $safe : '';
    }

    /**
     * Валидирует имя переменной.
     *
     * Правила:
     * - имя не может быть пустым после trim;
     * - длина не более 120 символов.
     *
     * @param string $name Имя переменной.
     * @return void
     *
     * @throws \InvalidArgumentException При нарушении правил.
     */
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

    /**
     * Нормализует описание переменной для записи в JSON.
     *
     * - null → пустая строка
     * - trim
     * - ограничение длины до 200 символов
     *
     * @param string|null $description Описание.
     * @return string Нормализованное описание.
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
     * Определяет тип данных для поля `dataType` (LLM-friendly метаданные).
     *
     * @param mixed $data Значение.
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
