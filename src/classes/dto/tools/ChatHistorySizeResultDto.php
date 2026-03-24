<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO результата инструмента получения размера истории чата.
 *
 * Используется инструментом chat_history.size для того, чтобы LLM могла быстро
 * понять, сколько сообщений доступно в полной истории (0-based индексация).
 *
 * Формат сериализации (toArray):
 * [
 *     'count' => int, // количество сообщений в истории
 * ]
 */
final class ChatHistorySizeResultDto implements IArrayable
{
    /**
     * @param int $count Количество сообщений в истории.
     */
    public function __construct(public readonly int $count)
    {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{count:int}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
        ];
    }
}
