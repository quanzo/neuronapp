<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\dto;

use app\modules\neuron\classes\safe\enums\RuleSeverityEnum;

/**
 * DTO метаданных safe-правила.
 *
 * Метаданные нужны для отключения правил по `ruleId`/`group`, документации и
 * тестов. DTO не содержит сам regex или исполняемую логику — только описание
 * политики.
 *
 * Пример:
 * ```php
 * $metadata = (new RuleMetadataDto())
 *     ->setRuleId('input.prompt.reset_ru')
 *     ->setGroup('input.prompt_injection')
 *     ->setSeverity(RuleSeverityEnum::HIGH)
 *     ->setDescription('Блокирует русскоязычную попытку сброса инструкций.')
 *     ->setFalsePositiveRisk('Минимальный риск для обычных пользовательских запросов.');
 * ```
 */
class RuleMetadataDto
{
    /** Стабильный идентификатор правила, используемый в `safe.*.disabled_rules`. */
    private string $ruleId = '';

    /** Группа правила, используемая в `safe.*.disabled_groups`. */
    private string $group = '';

    /** Уровень уверенности/критичности правила. */
    private RuleSeverityEnum $severity = RuleSeverityEnum::MEDIUM;

    /** Человекочитаемое описание назначения правила. */
    private string $description = '';

    /** Описание риска ложного срабатывания. */
    private string $falsePositiveRisk = '';

    /**
     * Возвращает стабильный идентификатор правила.
     */
    public function getRuleId(): string
    {
        return $this->ruleId;
    }

    /**
     * Устанавливает стабильный идентификатор правила.
     */
    public function setRuleId(string $ruleId): self
    {
        $this->ruleId = $ruleId;
        return $this;
    }

    /**
     * Возвращает группу правила.
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * Устанавливает группу правила.
     */
    public function setGroup(string $group): self
    {
        $this->group = $group;
        return $this;
    }

    /**
     * Возвращает уровень уверенности/критичности правила.
     */
    public function getSeverity(): RuleSeverityEnum
    {
        return $this->severity;
    }

    /**
     * Устанавливает уровень уверенности/критичности правила.
     */
    public function setSeverity(RuleSeverityEnum $severity): self
    {
        $this->severity = $severity;
        return $this;
    }

    /**
     * Возвращает описание назначения правила.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Устанавливает описание назначения правила.
     */
    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Возвращает описание риска ложного срабатывания.
     */
    public function getFalsePositiveRisk(): string
    {
        return $this->falsePositiveRisk;
    }

    /**
     * Устанавливает описание риска ложного срабатывания.
     */
    public function setFalsePositiveRisk(string $falsePositiveRisk): self
    {
        $this->falsePositiveRisk = $falsePositiveRisk;
        return $this;
    }

    /**
     * Возвращает метаданные в массиве для логов и документационных тестов.
     *
     * @return array{ruleId:string,group:string,severity:string,description:string,falsePositiveRisk:string}
     */
    public function toArray(): array
    {
        return [
            'ruleId'            => $this->ruleId,
            'group'             => $this->group,
            'severity'          => $this->severity->value,
            'description'       => $this->description,
            'falsePositiveRisk' => $this->falsePositiveRisk,
        ];
    }
}
