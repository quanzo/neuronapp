<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use function array_map;
use function is_array;
use function is_int;
use function is_string;

/**
 * DTO индекса промежуточных результатов для одной сессии.
 *
 * Хранится в `.store/intermediate_index_{sessionKey}.json` и позволяет быстро
 * получить список сохранённых промежуточных результатов.
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *   'schema'     => string, // neuronapp.intermediate_index.v1
 *   'sessionKey' => string,
 *   'items'      => array<IntermediateIndexItemDto::toArray()>,
 * ]
 * ```
 */
final class IntermediateIndexDto
{
    /**
     * @param string                    $schema     Версия схемы индекса.
     * @param string                    $sessionKey Базовый ключ сессии.
     * @param IntermediateIndexItemDto[] $items      Элементы индекса.
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
            $label = $item['label'] ?? null;
            $fileName = $item['fileName'] ?? null;
            $savedAt = $item['savedAt'] ?? null;
            $dataType = $item['dataType'] ?? null;
            $sizeBytes = $item['sizeBytes'] ?? null;

            if (!is_string($label) || !is_string($fileName) || !is_string($savedAt) || !is_string($dataType) || !is_int($sizeBytes)) {
                continue;
            }

            $dtoItems[] = new IntermediateIndexItemDto(
                label: $label,
                fileName: $fileName,
                savedAt: $savedAt,
                dataType: $dataType,
                sizeBytes: $sizeBytes,
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
     * @return array{schema: string, sessionKey: string, items: array<int, array{label: string, fileName: string, savedAt: string, dataType: string, sizeBytes: int}>}
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'sessionKey' => $this->sessionKey,
            'items' => array_map(
                static fn(IntermediateIndexItemDto $i): array => $i->toArray(),
                $this->items,
            ),
        ];
    }
}
