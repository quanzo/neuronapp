<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use NeuronAI\Chat\Messages\Message as NeuronMessage;

/**
 * DTO события отправки сообщения агентом.
 *
 * Для `agent.message.started` задаётся исходящее сообщение ({@see setOutgoingMessage}).
 * Для `agent.message.completed` — исходящее и входящее (ответ ассистента как {@see NeuronMessage}, если есть).
 * Для `agent.message.failed` используется {@see AgentMessageErrorEventDto} (исходящее сообщение для диагностики).
 *
 * Пример использования:
 * ```php
 * $event = (new AgentMessageEventDto())
 *     ->setOutgoingMessage($userMsg)
 *     ->setIncomingMessage($assistantMsg)
 *     ->setAttachmentsCount(2)
 *     ->setStructured(false)
 *     ->setDurationSeconds(1.23);
 * ```
 */
class AgentMessageEventDto extends BaseEventDto
{
    private ?NeuronMessage $outgoingMessage = null;

    private ?NeuronMessage $incomingMessage = null;

    private int $attachmentsCount = 0;

    private bool $structured = false;

    private float $durationSeconds = 0.0;

    /**
     * Возвращает отправленное в LLM сообщение (после прикрепления вложений к моменту события).
     */
    public function getOutgoingMessage(): ?NeuronMessage
    {
        return $this->outgoingMessage;
    }

    /**
     * Устанавливает отправляемое сообщение.
     *
     * @param NeuronMessage|null $outgoingMessage Сообщение, уходящее в агент.
     */
    public function setOutgoingMessage(?NeuronMessage $outgoingMessage): self
    {
        $this->outgoingMessage = $outgoingMessage;
        return $this;
    }

    /**
     * Возвращает ответ ассистента (для `agent.message.completed`, если удалось представить как сообщение).
     */
    public function getIncomingMessage(): ?NeuronMessage
    {
        return $this->incomingMessage;
    }

    /**
     * Устанавливает входящее сообщение (ответ ассистента).
     *
     * @param NeuronMessage|null $incomingMessage Ответ провайдера или null (например, structured DTO без сообщения).
     */
    public function setIncomingMessage(?NeuronMessage $incomingMessage): self
    {
        $this->incomingMessage = $incomingMessage;
        return $this;
    }

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
            'attachmentsCount'   => $this->attachmentsCount,
            'structured'         => $this->structured,
            'durationSeconds'    => $this->durationSeconds,
            'hasOutgoingMessage' => $this->outgoingMessage !== null,
            'hasIncomingMessage' => $this->incomingMessage !== null,
            'outgoingRole'       => $this->outgoingMessage?->getRole(),
            'incomingRole'       => $this->incomingMessage?->getRole(),
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        $outRole = $this->outgoingMessage !== null ? (string) $this->outgoingMessage->getRole() : '-';
        $inRole  = $this->incomingMessage !== null ? (string) $this->incomingMessage->getRole() : '-';

        return [
            'attachments' => $this->attachmentsCount,
            'structured'  => $this->structured ? 'yes' : 'no',
            'duration'    => round($this->durationSeconds, 2) . 's',
            'outRole'     => $outRole,
            'inRole'      => $inRole,
        ] + parent::buildStringParts();
    }
}
