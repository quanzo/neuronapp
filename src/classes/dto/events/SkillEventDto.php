<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\classes\dto\params\SkillRunParamsDto;

/**
 * DTO события Skill.
 *
 * Содержит ссылку на объект навыка и параметры его вызова.
 * Используется для событий `skill.started` и `skill.completed`.
 * Для события `skill.failed` используется наследник {@see SkillErrorEventDto}.
 *
 * Пример использования:
 * ```php
 * $event = (new SkillEventDto())
 *     ->setSkill($skill)
 *     ->setParams(['path' => 'file.txt']);
 *
 * echo (string) $event;
 * // [SkillEvent] skill=skill-file-block-summarize | params={"path":"file.txt"} | runId=... | agent=...
 * ```
 */
class SkillEventDto extends BaseEventDto
{
    private ?Skill $skill         = null;
    private ?SkillRunParamsDto $params = null;

    /**
     * Возвращает имя навыка или пустую строку, если навык не задан.
     */
    public function getSkillName(): string
    {
        return $this->skill?->getName() ?? '';
    }

    /**
     * Возвращает объект навыка.
     */
    public function getSkill(): ?Skill
    {
        return $this->skill;
    }

    /**
     * Устанавливает объект навыка.
     *
     * @param Skill $skill Экземпляр навыка.
     */
    public function setSkill(Skill $skill): self
    {
        $this->skill = $skill;
        return $this;
    }

    /**
     * Возвращает параметры выполнения навыка.
     */
    public function getParams(): ?SkillRunParamsDto
    {
        return $this->params;
    }

    /**
     * Устанавливает параметры выполнения Skill для логирования.
     *
     * @param array<string, mixed>|null $params Ассоциативный массив параметров навыка.
     */
    public function setParams(?array $params): self
    {
        $this->params = (new SkillRunParamsDto())->setParams($params);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + [
            'skillName' => $this->getSkillName(),
            'params'    => $this->params?->toArray(),
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        $paramsStr = $this->params !== null
            ? json_encode($this->params->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        return [
            'skill'  => $this->getSkillName(),
            'params' => $paramsStr ?: '',
        ] + parent::buildStringParts();
    }
}
