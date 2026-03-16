<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use function array_map;
use function is_array;
use function is_string;

/**
 * DTO ответа инструмента IntermediateTool.
 *
 * Возвращается LLM в виде JSON и содержит единый предсказуемый формат
 * для операций save/load/list/exist.
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *   'action'     => string,  // save|load|list|exist
 *   'success'    => bool,
 *   'message'    => string,
 *   'sessionKey' => string,
 *   'label'      => string|null,
 *   'fileName'   => string|null,
 *   'savedAt'    => string|null,
 *   'dataType'   => string|null,
 *   'data'       => mixed|null,
 *   'exists'     => bool|null,
 *   'items'      => array<IntermediateIndexItemDto::toArray()>|null,
 *   'count'      => int|null
 * ]
 * ```
 */
final class IntermediateToolResultDto
{
    /**
     * @param string                     $action     Операция.
     * @param bool                       $success    Успешность операции.
     * @param string                     $message    Человекочитаемое сообщение.
     * @param string                     $sessionKey Текущий sessionKey.
     * @param string|null                $label      Метка (если применимо).
     * @param string|null                $fileName   Имя файла (если применимо).
     * @param string|null                $savedAt    ISO-8601 время сохранения (если применимо).
     * @param string|null                $dataType   Тип данных (если применимо).
     * @param mixed|null                 $data       Данные (для load или echo save при необходимости).
     * @param bool|null                  $exists     Результат exist (если применимо).
     * @param IntermediateIndexItemDto[]|null $items Список (для list).
     * @param int|null                   $count      Количество элементов (для list).
     */
    public function __construct(
        public readonly string $action,
        public readonly bool $success,
        public readonly string $message,
        public readonly string $sessionKey,
        public readonly ?string $label = null,
        public readonly ?string $fileName = null,
        public readonly ?string $savedAt = null,
        public readonly ?string $dataType = null,
        public readonly mixed $data = null,
        public readonly ?bool $exists = null,
        public readonly ?array $items = null,
        public readonly ?int $count = null,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'success' => $this->success,
            'message' => $this->message,
            'sessionKey' => $this->sessionKey,
            'label' => $this->label,
            'fileName' => $this->fileName,
            'savedAt' => $this->savedAt,
            'dataType' => $this->dataType,
            'data' => $this->data,
            'exists' => $this->exists,
            'items' => $this->items === null
                ? null
                : array_map(static fn(IntermediateIndexItemDto $i): array => $i->toArray(), $this->items),
            'count' => $this->count,
        ];
    }
}
