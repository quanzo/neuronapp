<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\cmd;

/**
 * DTO команды "think" — алиас {@see ThinkingCmdDto} для синтаксиса "@@think(...)".
 *
 * Семантика идентична {@see ThinkingCmdDto}; отдельный класс нужен для
 * автоматического сопоставления имени команды в {@see CmdDto::resolveCommandClass()}.
 *
 * Пример:
 * ```php
 * $dto = CmdDto::fromString('@@think(false)');
 * $enabled = $dto instanceof ThinkCmdDto ? $dto->resolveEnabled() : null;
 * ```
 */
final class ThinkCmdDto extends ThinkingCmdDto
{
    /**
     * Создаёт DTO команды "think" из разобранных частей.
     *
     * @param string $name   Имя команды.
     * @param array  $params Позиционные параметры команды.
     *
     * @return self Экземпляр {@see ThinkCmdDto}.
     */
    protected static function fromParts(string $name, array $params): self
    {
        return new self($name, $params);
    }
}
