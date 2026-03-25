<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

use function array_map;
use function is_array;
use function is_int;
use function is_string;

/**
 * DTO индекса результатов для одной сессии.
 *
 * Хранится в `.store/var_index_{sessionKey}.json` и позволяет быстро
 * получить список сохранённых результатов.
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *   'schema'     => string, // neuronapp.var_index.v1
 *   'sessionKey' => string,
 *   'items'      => array<VarIndexItemDto::toArray()>,
 * ]
 * ```
 */
final class VarIndexDto implements IArrayable
{
    /**
     * @param string            $schema     Версия схемы индекса.
     * @param string            $sessionKey Базовый ключ сессии.
     * @param VarIndexItemDto[] $items      Элементы индекса.
     */
    public function __construct(
        public readonly string $schema,
        public readonly string $sessionKey,
        public readonly array $items,
    ) {
    }

    /**
     * Пытается восстановить индекс из массива, возвращая null при некорректной структуре.
     *
     * @param array<string,mixed> $data Декодированный JSON.
     * @return self|null
     */
    public static function tryFromArray(array $data): ?self
    {
        $schema = $data['schema'] ?? null;
        $sessionKey = $data['sessionKey'] ?? null;
        $items = $data['items'] ?? null;

        if (!is_string($schema) || !is_string($sessionKey) || !is_array($items)) {
            return null;
        }

        $dtoItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name        = $item['name'] ?? null;
            $description = $item['description'] ?? null;
            $fileName    = $item['fileName'] ?? null;
            $savedAt     = $item['savedAt'] ?? null;
            $dataType    = $item['dataType'] ?? null;
            $sizeBytes   = $item['sizeBytes'] ?? null;

            if (!is_string($name) || !is_string($description) || !is_string($fileName) || !is_string($savedAt) || !is_string($dataType) || !is_int($sizeBytes)) {
                continue;
            }

            $dtoItems[] = new VarIndexItemDto(
                name       : $name,
                description: $description,
                fileName   : $fileName,
                savedAt    : $savedAt,
                dataType   : $dataType,
                sizeBytes  : $sizeBytes,
            );
        }

        return new self(
            schema: $schema,
            sessionKey: $sessionKey,
            items: $dtoItems,
        );
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{schema: string, sessionKey: string, items: array<int, array{name: string, description: string, fileName: string, savedAt: string, dataType: string, sizeBytes: int}>}
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'sessionKey' => $this->sessionKey,
            'items' => array_map(
                static fn(VarIndexItemDto $i): array => $i->toArray(),
                $this->items,
            ),
        ];
    }
}
