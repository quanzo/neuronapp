<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use NeuronAI\Chat\Messages\Message as NeuronMessage;

/**
 * DTO события завершённого шага диалога с LLM (пара user → assistant после инференса).
 *
 * Используется событием {@see \app\modules\neuron\enums\EventNameEnum::LLM_TURN_COMPLETED} для долговременной памяти
 * и наблюдаемости. Сообщения могут быть null, если соответствующая сторона недоступна.
 *
 * Пример:
 *
 * ```php
 * $dto = (new LlmTurnCompletedEventDto())
 *     ->setUserId(1)
 *     ->setSessionKey('20260412-120000-1-0')
 *     ->setUserMessage($userMsg)
 *     ->setAssistantMessage($assistantMsg);
 * ```
 */
class LlmTurnCompletedEventDto extends BaseEventDto
{
    /**
     * @var int|string
     */
    private int|string $userId = 0;

    private ?NeuronMessage $userMessage = null;

    private ?NeuronMessage $assistantMessage = null;

    /**
     * Возвращает id пользователя приложения.
     *
     * @return int|string
     */
    public function getUserId(): int|string
    {
        return $this->userId;
    }

    /**
     * Устанавливает id пользователя.
     *
     * @param int|string $userId Значение из {@see \app\modules\neuron\classes\config\ConfigurationApp::getUserId()}.
     */
    public function setUserId(int|string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * Возвращает последнее user-сообщение шага (если найдено).
     */
    public function getUserMessage(): ?NeuronMessage
    {
        return $this->userMessage;
    }

    /**
     * Устанавливает user-сообщение шага.
     *
     * @param NeuronMessage|null $userMessage Сообщение пользователя или null.
     */
    public function setUserMessage(?NeuronMessage $userMessage): self
    {
        $this->userMessage = $userMessage;
        return $this;
    }

    /**
     * Возвращает ответ ассистента шага (если есть).
     */
    public function getAssistantMessage(): ?NeuronMessage
    {
        return $this->assistantMessage;
    }

    /**
     * Устанавливает ответ ассистента.
     *
     * @param NeuronMessage|null $assistantMessage Сообщение ассистента или null.
     */
    public function setAssistantMessage(?NeuronMessage $assistantMessage): self
    {
        $this->assistantMessage = $assistantMessage;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + [
            'userId' => $this->userId,
            'hasUserMessage' => $this->userMessage !== null,
            'hasAssistantMessage' => $this->assistantMessage !== null,
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        return [
            'userId' => is_string($this->userId) ? $this->userId : (string) $this->userId,
            'userMsg' => $this->userMessage !== null ? 'yes' : 'no',
            'asstMsg' => $this->assistantMessage !== null ? 'yes' : 'no',
        ] + parent::buildStringParts();
    }
}
