<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IErrorEvent;
use app\modules\neuron\traits\HasErrorInfoTrait;

/**
 * DTO события ошибки отправки сообщения агентом.
 *
 * Расширяет {@see AgentMessageEventDto} полями ошибки (errorClass, errorMessage).
 * Реализует {@see IErrorEvent} для единообразного распознавания ошибочных событий.
 * Используется для события `agent.message.failed`.
 *
 * Пример использования:
 * ```php
 * $event = (new AgentMessageErrorEventDto())
 *     ->setAttachmentsCount(1)
 *     ->setStructured(false)
 *     ->setDurationSeconds(30.0)
 *     ->setErrorClass(\RuntimeException::class)
 *     ->setErrorMessage('API rate limit');
 *
 * echo (string) $event;
 * // [AgentMessageErrorEvent] attachments=1 | structured=no | duration=30.0s | error=RuntimeException: "API rate limit" | ...
 * ```
 */
class AgentMessageErrorEventDto extends AgentMessageEventDto implements IErrorEvent
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
            if ($key === 'duration') {
                foreach ($own as $k => $v) {
                    $base[$k] = $v;
                }
            }
        }

        return $base ?: array_merge($own, $parent);
    }
}
