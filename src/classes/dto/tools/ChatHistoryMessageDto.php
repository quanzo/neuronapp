<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO сообщения из истории чата.
 *
 * Используется инструментом chat_history.message для получения конкретного сообщения
 * по индексу (0-based) в LLM-friendly виде.
 *
 * Формат сериализации (toArray):
 * [
 *     'index'         => int,         // индекс сообщения в истории (0-based)
 *     'role'          => string,      // роль отправителя сообщения
 *     'content'       => string,      // тело сообщения
 *     'toolSignature' => array|null,  // ToolSignatureDto::toArray() или null
 * ]
 */
final class ChatHistoryMessageDto implements IArrayable
{
    /**
     * @param int                  $index Индекс сообщения (0-based).
     * @param string               $role  Роль сообщения.
     * @param string               $content Текст сообщения.
     * @param ToolSignatureDto|null $toolSignature Сигнатура инструмента (если применимо).
     */
    public function __construct(
        public readonly int $index,
        public readonly string $role,
        public readonly string $content,
        public readonly ?ToolSignatureDto $toolSignature = null,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{index:int, role:string, content:string, toolSignature:array|null}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'role' => $this->role,
            'content' => $this->content,
            'toolSignature' => $this->toolSignature?->toArray(),
        ];
    }
}
