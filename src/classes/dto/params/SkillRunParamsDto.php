<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\params;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO параметров выполнения Skill для логирования.
 *
 * Нужен, чтобы не хранить структурные данные "голым" массивом
 * внутри event DTO, но при этом уметь сериализовать их в `toArray()`.
 */
final class SkillRunParamsDto implements IArrayable
{
    /**
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * @param array<string, mixed>|null $params
     *
     * @return $this
     */
    public function setParams(?array $params): self
    {
        $this->params = $params ?? [];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->params;
    }
}
