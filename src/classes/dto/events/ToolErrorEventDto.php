<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IErrorEvent;
use app\modules\neuron\traits\HasErrorInfoTrait;

/**
 * DTO события ошибки инструмента (tool).
 *
 * Расширяет {@see ToolEventDto} полями ошибки (errorClass, errorMessage).
 * Реализует {@see IErrorEvent} для единообразного распознавания ошибочных событий.
 * Используется для события `tool.failed`.
 *
 * Пример использования:
 * ```php
 * $event = (new ToolErrorEventDto())
 *     ->setToolName('bash')
 *     ->setErrorClass(\RuntimeException::class)
 *     ->setErrorMessage('command failed');
 *
 * echo (string) $event;
 * // [ToolErrorEvent] tool=bash | error=RuntimeException: "command failed" | runId=... | agent=...
 * ```
 */
class ToolErrorEventDto extends ToolEventDto implements IErrorEvent
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
            if ($key === 'tool') {
                foreach ($own as $k => $v) {
                    $base[$k] = $v;
                }
            }
        }

        return $base ?: array_merge($own, $parent);
    }
}
