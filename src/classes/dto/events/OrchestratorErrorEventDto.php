<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IErrorEvent;
use app\modules\neuron\traits\HasErrorInfoTrait;

/**
 * DTO события ошибки оркестратора.
 *
 * Расширяет {@see OrchestratorEventDto} полями ошибки (errorClass, errorMessage).
 * Реализует {@see IErrorEvent} для единообразного распознавания ошибочных событий.
 * Используется для события `orchestrator.failed`.
 *
 * Пример использования:
 * ```php
 * $event = (new OrchestratorErrorEventDto())
 *     ->setIterations(3)
 *     ->setRestartCount(1)
 *     ->setErrorClass(\RuntimeException::class)
 *     ->setErrorMessage('max iterations exceeded');
 *
 * echo (string) $event;
 * // [OrchestratorErrorEvent] iterations=3 | restarts=1 | error=RuntimeException: "max iterations exceeded" | ...
 * ```
 */
class OrchestratorErrorEventDto extends OrchestratorEventDto implements IErrorEvent
{
    use HasErrorInfoTrait;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + $this->errorInfoToArray();
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        $own    = $this->buildErrorStringParts();
        $parent = parent::buildStringParts();

        $base = [];
        foreach ($parent as $key => $value) {
            $base[$key] = $value;
            if ($key === 'reason') {
                foreach ($own as $k => $v) {
                    $base[$k] = $v;
                }
            }
        }

        return $base ?: array_merge($own, $parent);
    }
}
