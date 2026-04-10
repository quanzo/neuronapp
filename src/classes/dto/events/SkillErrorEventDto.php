<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\interfaces\IErrorEvent;
use app\modules\neuron\traits\HasErrorInfoTrait;

/**
 * DTO события ошибки навыка (Skill).
 *
 * Расширяет {@see SkillEventDto} полями ошибки (errorClass, errorMessage).
 * Реализует {@see IErrorEvent} для единообразного распознавания ошибочных событий.
 * Используется для события `skill.failed`.
 *
 * Пример использования:
 * ```php
 * $event = (new SkillErrorEventDto())
 *     ->setSkill($skill)
 *     ->setErrorClass(\RuntimeException::class)
 *     ->setErrorMessage('LLM timeout');
 *
 * echo (string) $event;
 * // [SkillErrorEvent] skill=text-finder | error=RuntimeException: "LLM timeout" | runId=... | agent=...
 * ```
 */
class SkillErrorEventDto extends SkillEventDto implements IErrorEvent
{
    use HasErrorInfoTrait;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + $this->errorInfoToArray();
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        $own    = $this->buildErrorStringParts();
        $parent = parent::buildStringParts();

        $base = [];
        foreach ($parent as $key => $value) {
            $base[$key] = $value;
            if ($key === 'params') {
                foreach ($own as $k => $v) {
                    $base[$k] = $v;
                }
            }
        }

        return $base ?: array_merge($own, $parent);
    }
}
