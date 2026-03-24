<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

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
 *   'description'=> string|null,
 *   'savedAt'    => string|null,
 *   'dataType'   => string|null,
 *   'data'       => mixed|null,
 *   'exists'     => bool|null,
 *   'items'      => array<IntermediateIndexItemDto::toArray()>|null,
 *   'count'      => int|null,
 *   'totalCount' => int|null,
 *   'page'       => int|null,
 *   'pageSize'   => int|null,
 *   'query'      => string|null,
 *   'startLine'  => int|null,
 *   'endLine'    => int|null,
 *   'totalLines' => int|null,
 *   'truncated'  => bool|null
 * ]
 * ```
 */
final class IntermediateToolResultDto implements IArrayable
{
    /**
     * @param string                     $action     Операция.
     * @param bool                       $success    Успешность операции.
     * @param string                     $message    Человекочитаемое сообщение.
     * @param string                     $sessionKey Текущий sessionKey.
     * @param string|null                $label      Метка (если применимо).
     * @param string|null                $fileName   Имя файла (если применимо).
     * @param string|null                $description Краткое описание результата (если применимо).
     * @param string|null                $savedAt    ISO-8601 время сохранения (если применимо).
     * @param string|null                $dataType   Тип данных (если применимо).
     * @param mixed|null                 $data       Данные (для load или echo save при необходимости).
     * @param bool|null                  $exists     Результат exist (если применимо).
     * @param IntermediateIndexItemDto[]|null $items Список (для list).
     * @param int|null                   $count      Количество элементов (для list).
     * @param int|null                   $totalCount Общее количество элементов (для list с фильтрацией/пагинацией).
     * @param int|null                   $page       Номер страницы (1-based).
     * @param int|null                   $pageSize   Размер страницы.
     * @param string|null                $query      Строка поиска (для list).
     * @param int|null                   $startLine  Начальная строка (1-based) для строковых данных (load).
     * @param int|null                   $endLine    Конечная строка (1-based) для строковых данных (load).
     * @param int|null                   $totalLines Общее число строк в строковых данных (load).
     * @param bool|null                  $truncated  Был ли результат усечён (load диапазона).
     */
    public function __construct(
        public readonly string $action,
        public readonly bool $success,
        public readonly string $message,
        public readonly string $sessionKey,
        public readonly ?string $label = null,
        public readonly ?string $fileName = null,
        public readonly ?string $description = null,
        public readonly ?string $savedAt = null,
        public readonly ?string $dataType = null,
        public readonly mixed $data = null,
        public readonly ?bool $exists = null,
        public readonly ?array $items = null,
        public readonly ?int $count = null,
        public readonly ?int $totalCount = null,
        public readonly ?int $page = null,
        public readonly ?int $pageSize = null,
        public readonly ?string $query = null,
        public readonly ?int $startLine = null,
        public readonly ?int $endLine = null,
        public readonly ?int $totalLines = null,
        public readonly ?bool $truncated = null,
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
            'description' => $this->description,
            'savedAt' => $this->savedAt,
            'dataType' => $this->dataType,
            'data' => $this->data,
            'exists' => $this->exists,
            'items' => $this->items === null
                ? null
                : array_map(static fn(IntermediateIndexItemDto $i): array => $i->toArray(), $this->items),
            'count' => $this->count,
            'totalCount' => $this->totalCount,
            'page' => $this->page,
            'pageSize' => $this->pageSize,
            'query' => $this->query,
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'totalLines' => $this->totalLines,
            'truncated' => $this->truncated,
        ];
    }
}
