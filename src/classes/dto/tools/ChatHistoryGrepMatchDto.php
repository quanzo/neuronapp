<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO одного совпадения в результате поиска по истории чата.
 *
 * Используется инструментом chat_history.grep для возврата совпадений
 * с привязкой к сообщению и строке внутри сообщения.
 *
 * Формат сериализации (toArray):
 * [
 *     'index'         => int,         // индекс сообщения в полной истории (0-based)
 *     'role'          => string,      // роль сообщения
 *     'lineNumber'    => int,         // номер строки внутри content (1-based)
 *     'lineContent'   => string,      // строка текста (усечённая)
 *     'matchText'     => string,      // совпавший фрагмент (усечённый)
 *     'toolSignature' => array|null,  // ToolSignatureDto::toArray() или null
 * ]
 */
final class ChatHistoryGrepMatchDto implements IArrayable
{
    /**
     * @param int                   $index Индекс сообщения в истории (0-based).
     * @param string                $role Роль сообщения.
     * @param int                   $lineNumber Номер строки внутри сообщения (1-based).
     * @param string                $lineContent Содержимое строки (может быть усечено).
     * @param string                $matchText Совпавший фрагмент (может быть усечён).
     * @param ToolSignatureDto|null $toolSignature Сигнатура инструмента (если применимо).
     */
    public function __construct(
        public readonly int $index,
        public readonly string $role,
        public readonly int $lineNumber,
        public readonly string $lineContent,
        public readonly string $matchText,
        public readonly ?ToolSignatureDto $toolSignature = null,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{
     *   index:int,
     *   role:string,
     *   lineNumber:int,
     *   lineContent:string,
     *   matchText:string,
     *   toolSignature:array|null
     * }
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'role' => $this->role,
            'lineNumber' => $this->lineNumber,
            'lineContent' => $this->lineContent,
            'matchText' => $this->matchText,
            'toolSignature' => $this->toolSignature?->toArray(),
        ];
    }
}
