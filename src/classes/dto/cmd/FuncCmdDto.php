<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\cmd;

/**
 * DTO команды "func", представляемой строкой вида "@@func(...)".
 *
 * Класс конкретизирует базовый {@see CmdDto} для команды с именем "func" и
 * служит точкой расширения: при необходимости в будущем можно добавить
 * специфичную для команды "func" валидацию или дополнительные методы.
 */
final class FuncCmdDto extends CmdDto
{
    /**
     * Создаёт DTO команды "func" из разобранных частей.
     *
     * @param string $name   Имя команды.
     * @param array  $params Позиционные параметры команды.
     *
     * @return self Экземпляр {@see FuncCmdDto}.
     */
    protected static function fromParts(string $name, array $params): self
    {
        return new self($name, $params);
    }
}
