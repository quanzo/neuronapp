<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

/**
 * DTO события инструмента (tool).
 *
 * Содержит имя инструмента. Используется для событий `tool.started` и `tool.completed`.
 * Для события `tool.failed` используется наследник {@see ToolErrorEventDto}.
 *
 * Пример использования:
 * ```php
 * $event = (new ToolEventDto())
 *     ->setToolName('chunk_view');
 *
 * echo (string) $event;
 * // [ToolEvent] tool=chunk_view | runId=... | agent=...
 * ```
 */
class ToolEventDto extends BaseEventDto
{
    private string $toolName = '';

    /**
     * Возвращает имя инструмента.
     */
    public function getToolName(): string
    {
        return $this->toolName;
    }

    /**
     * Устанавливает имя инструмента.
     *
     * @param string $toolName Имя инструмента (напр. `chunk_view`, `bash`).
     */
    public function setToolName(string $toolName): self
    {
        $this->toolName = $toolName;
        return $this;
    }

    /**
     * Преобразует DTO в массив для логирования/сериализации.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'toolName' => $this->toolName,
        ]);
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        return [
            'tool' => $this->toolName,
        ] + parent::buildStringParts();
    }
}
