<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события подготовки инференса LLM.
 *
 * Содержит информацию о контексте, подготовленном перед отправкой запроса
 * к провайдеру: инструкции (system prompt), список инструментов,
 * превью пользовательского сообщения и (опционально) полный набор сообщений.
 *
 * Используется для события `llm.inference.prepared`.
 *
 * Пример использования:
 * ```php
 * $event = (new LlmInferenceEventDto())
 *     ->setToolsCount(5)
 *     ->setToolsNames(['bash', 'chunk_view'])
 *     ->setInstructionsPreview('You are an assistant...')
 *     ->setInstructionsLength(1200)
 *     ->setUserMessagePreview('Какие у тебя инструменты?')
 *     ->setUserMessageLength(28);
 *
 * echo (string) $event;
 * // [LlmInferenceEvent] tools=5 | instructions=1200ch | userMsg="Какие у тебя..." | runId=... | agent=...
 * ```
 */
class LlmInferenceEventDto extends BaseEventDto
{
    private int $toolsCount = 0;

    /** @var list<string> */
    private array $toolsNames = [];

    /** @var array<string, list<string>> */
    private array $toolRequiredParams = [];

    private string $instructionsPreview = '';
    private int $instructionsLength = 0;

    private string $userMessagePreview = '';
    private int $userMessageLength = 0;

    private ?int $messagesCount = null;

    /** @var array<int, mixed>|null */
    private ?array $messagesSanitized = null;

    /**
     * Возвращает количество инструментов в запросе.
     */
    public function getToolsCount(): int
    {
        return $this->toolsCount;
    }

    /**
     * Устанавливает количество инструментов.
     *
     * @param int $toolsCount Число инструментов, переданных провайдеру.
     */
    public function setToolsCount(int $toolsCount): self
    {
        $this->toolsCount = $toolsCount;
        return $this;
    }

    /**
     * Возвращает имена инструментов.
     *
     * @return list<string>
     */
    public function getToolsNames(): array
    {
        return $this->toolsNames;
    }

    /**
     * Устанавливает имена инструментов.
     *
     * @param list<string> $toolsNames Список имён инструментов.
     */
    public function setToolsNames(array $toolsNames): self
    {
        $this->toolsNames = $toolsNames;
        return $this;
    }

    /**
     * Возвращает required-параметры по каждому инструменту.
     *
     * @return array<string, list<string>>
     */
    public function getToolRequiredParams(): array
    {
        return $this->toolRequiredParams;
    }

    /**
     * Устанавливает required-параметры инструментов.
     *
     * @param array<string, list<string>> $toolRequiredParams Карта tool_name => required params.
     */
    public function setToolRequiredParams(array $toolRequiredParams): self
    {
        $this->toolRequiredParams = $toolRequiredParams;
        return $this;
    }

    /**
     * Возвращает превью системного промпта (instructions).
     */
    public function getInstructionsPreview(): string
    {
        return $this->instructionsPreview;
    }

    /**
     * Устанавливает превью системного промпта.
     *
     * @param string $instructionsPreview Усечённый текст инструкций.
     */
    public function setInstructionsPreview(string $instructionsPreview): self
    {
        $this->instructionsPreview = $instructionsPreview;
        return $this;
    }

    /**
     * Возвращает полную длину системного промпта в символах.
     */
    public function getInstructionsLength(): int
    {
        return $this->instructionsLength;
    }

    /**
     * Устанавливает длину системного промпта.
     *
     * @param int $instructionsLength Длина в символах.
     */
    public function setInstructionsLength(int $instructionsLength): self
    {
        $this->instructionsLength = $instructionsLength;
        return $this;
    }

    /**
     * Возвращает однострочное превью последнего user-сообщения.
     */
    public function getUserMessagePreview(): string
    {
        return $this->userMessagePreview;
    }

    /**
     * Устанавливает превью последнего user-сообщения.
     *
     * @param string $userMessagePreview Усечённый однострочный текст.
     */
    public function setUserMessagePreview(string $userMessagePreview): self
    {
        $this->userMessagePreview = $userMessagePreview;
        return $this;
    }

    /**
     * Возвращает полную длину последнего user-сообщения в символах.
     */
    public function getUserMessageLength(): int
    {
        return $this->userMessageLength;
    }

    /**
     * Устанавливает длину user-сообщения.
     *
     * @param int $userMessageLength Длина в символах.
     */
    public function setUserMessageLength(int $userMessageLength): self
    {
        $this->userMessageLength = $userMessageLength;
        return $this;
    }

    /**
     * Возвращает количество сообщений в истории (только в debug-режиме).
     */
    public function getMessagesCount(): ?int
    {
        return $this->messagesCount;
    }

    /**
     * Устанавливает количество сообщений в истории.
     *
     * @param ?int $messagesCount Число сообщений или null (summary-режим).
     */
    public function setMessagesCount(?int $messagesCount): self
    {
        $this->messagesCount = $messagesCount;
        return $this;
    }

    /**
     * Возвращает санитизированный массив сообщений (только в debug-режиме).
     *
     * @return array<int, mixed>|null
     */
    public function getMessagesSanitized(): ?array
    {
        return $this->messagesSanitized;
    }

    /**
     * Устанавливает санитизированный массив сообщений.
     *
     * @param array<int, mixed>|null $messagesSanitized Массив или null (summary-режим).
     */
    public function setMessagesSanitized(?array $messagesSanitized): self
    {
        $this->messagesSanitized = $messagesSanitized;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = parent::toArray() + [
            'toolsCount'          => $this->toolsCount,
            'toolsNames'          => $this->toolsNames,
            'toolRequiredParams'  => $this->toolRequiredParams,
            'instructionsPreview' => $this->instructionsPreview,
            'instructionsLength'  => $this->instructionsLength,
            'userMessagePreview'  => $this->userMessagePreview,
            'userMessageLength'   => $this->userMessageLength,
        ];

        if ($this->messagesCount !== null) {
            $result['messagesCount'] = $this->messagesCount;
        }
        if ($this->messagesSanitized !== null) {
            $result['messagesSanitized'] = $this->messagesSanitized;
        }

        return $result;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        $parts = [
            'tools'        => $this->toolsCount,
            'instructions' => $this->instructionsLength . 'ch',
        ];

        if ($this->userMessagePreview !== '') {
            $preview = mb_strlen($this->userMessagePreview) > 80
                ? mb_substr($this->userMessagePreview, 0, 80) . '...'
                : $this->userMessagePreview;
            $parts['userMsg'] = $preview;
        }

        if ($this->messagesCount !== null) {
            $parts['messages'] = $this->messagesCount;
        }

        return $parts + parent::buildStringParts();
    }
}
