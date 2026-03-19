<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO сигнатуры вызова инструмента в истории сообщений.
 *
 * Используется инструментами chat_history.meta и chat_history.message:
 * - когда сообщение является tool-call или tool-result;
 * - чтобы LLM могла понять, какой именно инструмент был вызван и с чем.
 *
 * Поле raw содержит «сырой» фрагмент данных (например, сериализованную форму
 * объекта сообщения), если извлечь name/arguments структурированно не удалось.
 *
 * Формат сериализации (toArray):
 * [
 *     'name'      => string|null,
 *     'arguments' => mixed|null,
 *     'raw'       => mixed|null,
 * ]
 */
final class ToolSignatureDto
{
    /**
     * @param string|null $name Имя инструмента.
     * @param mixed|null  $arguments Аргументы вызова инструмента (если доступны).
     * @param mixed|null  $raw Fallback-данные для отладки/распознавания.
     */
    public function __construct(
        public readonly ?string $name,
        public readonly mixed $arguments = null,
        public readonly mixed $raw = null,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{name:?string, arguments:mixed, raw:mixed}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'arguments' => $this->arguments,
            'raw' => $this->raw,
        ];
    }
}
