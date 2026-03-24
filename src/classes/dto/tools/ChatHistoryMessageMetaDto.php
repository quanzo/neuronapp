<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO метаданных сообщения из истории чата без загрузки текста.
 *
 * Используется инструментом chat_history.meta для быстрого "peek" по индексу.
 *
 * Формат сериализации (toArray):
 * [
 *     'index'         => int,              // индекс сообщения в истории (0-based)
 *     'role'          => string,           // роль отправителя сообщения
 *     'chars'         => int,              // длина content в символах (UTF-8)
 *     'isTool'        => bool,             // признак tool-call/tool-result
 *     'toolSignature' => array|null,       // ToolSignatureDto::toArray() или null
 * ]
 */
final class ChatHistoryMessageMetaDto implements IArrayable
{
    /**
     * @param int                  $index Индекс сообщения (0-based).
     * @param string               $role  Роль сообщения.
     * @param int                  $chars Размер сообщения в символах.
     * @param bool                 $isTool Признак tool-call/tool-result.
     * @param ToolSignatureDto|null $toolSignature Сигнатура инструмента (если применимо).
     */
    public function __construct(
        public readonly int $index,
        public readonly string $role,
        public readonly int $chars,
        public readonly bool $isTool,
        public readonly ?ToolSignatureDto $toolSignature = null,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{index:int, role:string, chars:int, isTool:bool, toolSignature:array|null}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'role' => $this->role,
            'chars' => $this->chars,
            'isTool' => $this->isTool,
            'toolSignature' => $this->toolSignature?->toArray(),
        ];
    }
}
