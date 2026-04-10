<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события отправки сообщения агентом.
 *
 * Описывает цикл отправки сообщения в LLM: количество вложений, режим structured output,
 * длительность запроса. Используется для событий `agent.message.started` и `agent.message.completed`.
 * Для события `agent.message.failed` используется наследник {@see AgentMessageErrorEventDto}.
 *
 * Пример использования:
 * ```php
 * $event = (new AgentMessageEventDto())
 *     ->setAttachmentsCount(2)
 *     ->setStructured(false)
 *     ->setDurationSeconds(1.23);
 *
 * echo (string) $event;
 * // [AgentMessageEvent] attachments=2 | structured=no | duration=1.23s | runId=... | agent=...
 * ```
 */
class AgentMessageEventDto extends BaseEventDto
{
    private int $attachmentsCount = 0;
    private bool $structured = false;
    private float $durationSeconds = 0.0;

    /**
     * Возвращает количество вложений в сообщении.
     */
    public function getAttachmentsCount(): int
    {
        return $this->attachmentsCount;
    }

    /**
     * Устанавливает количество вложений.
     *
     * @param int $attachmentsCount Число файлов/контекстных вложений.
     */
    public function setAttachmentsCount(int $attachmentsCount): self
    {
        $this->attachmentsCount = $attachmentsCount;
        return $this;
    }

    /**
     * Возвращает, используется ли structured output.
     */
    public function isStructured(): bool
    {
        return $this->structured;
    }

    /**
     * Устанавливает флаг structured output.
     *
     * @param bool $structured true, если LLM-запрос использует structured output.
     */
    public function setStructured(bool $structured): self
    {
        $this->structured = $structured;
        return $this;
    }

    /**
     * Возвращает длительность запроса к LLM в секундах.
     */
    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    /**
     * Устанавливает длительность запроса к LLM.
     *
     * @param float $durationSeconds Время выполнения запроса в секундах.
     */
    public function setDurationSeconds(float $durationSeconds): self
    {
        $this->durationSeconds = $durationSeconds;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + [
            'attachmentsCount' => $this->attachmentsCount,
            'structured'       => $this->structured,
            'durationSeconds'  => $this->durationSeconds,
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        return [
            'attachments' => $this->attachmentsCount,
            'structured'  => $this->structured ? 'yes' : 'no',
            'duration'    => round($this->durationSeconds, 2) . 's',
        ] + parent::buildStringParts();
    }
}
