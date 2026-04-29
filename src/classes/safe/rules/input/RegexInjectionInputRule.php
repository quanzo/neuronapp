<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputDetectorRuleInterface;
use app\modules\neuron\classes\safe\dto\InputViolationDto;
use app\modules\neuron\classes\safe\dto\RuleMetadataDto;
use app\modules\neuron\classes\safe\enums\RuleSeverityEnum;

/**
 * Универсальное regex-правило обнаружения prompt-injection признаков.
 *
 * Правило применяет один PCRE-паттерн к уже очищенному входному тексту.
 * Оно подходит для high/medium-confidence сигналов, где достаточно одного
 * совпадения для блокировки сообщения.
 *
 * Пример:
 * ```php
 * $rule = new RegexInjectionInputRule(
 *     'input.prompt.reset_ru',
 *     'Input asks to forget instructions.',
 *     '/забудь\\s+(все\\s+)?(инструкции|правила|промпт)/iu',
 *     'input.prompt_injection',
 *     RuleSeverityEnum::HIGH,
 *     'Низкий риск: обычные запросы редко просят забыть инструкции.'
 * );
 * ```
 */
class RegexInjectionInputRule implements InputDetectorRuleInterface
{
    /**
     * @param string           $code              Машиночитаемый код нарушения и ruleId.
     * @param string           $reason            Описание нарушения.
     * @param string           $pattern           PCRE-паттерн.
     * @param string           $group             Группа правила для отключения пачкой.
     * @param RuleSeverityEnum $severity          Уровень уверенности/критичности.
     * @param string           $falsePositiveRisk Описание риска ложного срабатывания.
     */
    public function __construct(
        private readonly string $code,
        private readonly string $reason,
        private readonly string $pattern,
        private readonly string $group = 'input.prompt_injection',
        private readonly RuleSeverityEnum $severity = RuleSeverityEnum::MEDIUM,
        private readonly string $falsePositiveRisk = 'Regex rule requires tests on project prompts before broadening.'
    ) {
    }

    /**
     * Возвращает метаданные правила для фильтрации по id/group и документации.
     *
     * @return RuleMetadataDto DTO с ruleId, group, severity и описанием.
     */
    public function getMetadata(): RuleMetadataDto
    {
        return (new RuleMetadataDto())
            ->setRuleId($this->code)
            ->setGroup($this->group)
            ->setSeverity($this->severity)
            ->setDescription($this->reason)
            ->setFalsePositiveRisk($this->falsePositiveRisk);
    }

    /**
     * @inheritDoc
     */
    public function detect(string $text): ?InputViolationDto
    {
        $matched = [];
        $isMatch = preg_match($this->pattern, $text, $matched);
        if ($isMatch !== 1) {
            return null;
        }

        $fragment = '';
        if (isset($matched[0]) && is_string($matched[0])) {
            $fragment = mb_substr($matched[0], 0, 240, 'UTF-8');
        }

        return (new InputViolationDto())
            ->setCode($this->code)
            ->setReason($this->reason)
            ->setMatchedFragment($fragment);
    }
}
