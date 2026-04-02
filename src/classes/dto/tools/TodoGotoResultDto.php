<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO результата инструмента перехода по TodoList ({@see \app\modules\neuron\tools\TodoGotoTool}).
 *
 * Пример использования:
 * - инструмент получает `point` (1-based);
 * - возвращает сериализованный результат для LLM.
 */
final class TodoGotoResultDto implements IArrayable
{
    /**
     * @param bool        $success     Успешность операции.
     * @param string      $message     Краткое сообщение о результате.
     * @param int|null    $fromPoint   Индекс текущего пункта (1-based) или null, если неизвестен.
     * @param int|null    $toPoint     Целевой номер пункта (1-based), как передан в инструмент.
     * @param string|null $reason      Пояснение причины перехода.
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?int $fromPoint = null,
        public readonly ?int $toPoint = null,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Преобразует DTO в массив для JSON-сериализации.
     *
     * @return array{success: bool, message: string, fromPoint: int|null, toPoint: int|null, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'fromPoint' => $this->fromPoint,
            'toPoint' => $this->toPoint,
            'reason' => $this->reason,
        ];
    }
}
