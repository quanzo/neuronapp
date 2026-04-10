<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IErrorEvent;
use app\modules\neuron\traits\HasErrorInfoTrait;

/**
 * DTO события ошибки уровня run.
 *
 * Расширяет {@see RunEventDto} полями ошибки (errorClass, errorMessage).
 * Реализует {@see IErrorEvent} для единообразного распознавания ошибочных событий.
 * Используется для события `run.failed`.
 *
 * Пример использования:
 * ```php
 * $event = (new RunErrorEventDto())
 *     ->setType('todolist')
 *     ->setName('review')
 *     ->setSteps(0)
 *     ->setErrorClass(\RuntimeException::class)
 *     ->setErrorMessage('timeout');
 *
 * echo (string) $event;
 * // [RunErrorEvent] type=todolist | name=review | steps=0 | error=RuntimeException: "timeout" | runId=... | agent=...
 * ```
 */
class RunErrorEventDto extends RunEventDto implements IErrorEvent
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
            if ($key === 'steps') {
                foreach ($own as $k => $v) {
                    $base[$k] = $v;
                }
            }
        }

        return $base ?: array_merge($own, $parent);
    }
}
