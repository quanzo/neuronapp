<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO результата инструмента перехода по TodoList ({@see \app\modules\neuron\tools\TodoGotoTool}).
 *
 * Пример использования:
 * - инструмент получает `target_point` (1-based);
 * - преобразует его в `toIndex` (0-based);
 * - возвращает сериализованный результат для LLM.
 */
final class TodoGotoResultDto
{
    /**
     * @param bool        $success     Успешность операции.
     * @param string      $message     Краткое сообщение о результате.
     * @param int|null    $fromIndex   Индекс текущего пункта (0-based) или null, если неизвестен.
     * @param int|null    $toIndex     Целевой индекс (0-based) или null, если переход не принят.
     * @param int|null    $targetPoint Целевой номер пункта (1-based), как передан в инструмент.
     * @param string|null $reason      Пояснение причины перехода.
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?int $fromIndex = null,
        public readonly ?int $toIndex = null,
        public readonly ?int $targetPoint = null,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Преобразует DTO в массив для JSON-сериализации.
     *
     * @return array{success: bool, message: string, fromIndex: int|null, toIndex: int|null, targetPoint: int|null, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'fromIndex' => $this->fromIndex,
            'toIndex' => $this->toIndex,
            'targetPoint' => $this->targetPoint,
            'reason' => $this->reason,
        ];
    }
}
