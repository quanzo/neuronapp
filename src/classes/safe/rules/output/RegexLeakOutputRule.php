<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\output;

use app\modules\neuron\classes\safe\contracts\OutputDetectorRuleInterface;
use app\modules\neuron\classes\safe\dto\OutputRuleResultDto;
use app\modules\neuron\classes\safe\dto\OutputViolationDto;
use app\modules\neuron\classes\safe\dto\RuleMetadataDto;
use app\modules\neuron\classes\safe\enums\RuleSeverityEnum;

/**
 * Универсальное regex-правило утечки в выходном тексте LLM.
 *
 * Правило ищет чувствительный фрагмент в ответе модели и заменяет его на
 * безопасную строку. Оно не блокирует ответ целиком: решение о сигнализации
 * принимает `OutputSafe`/декоратор провайдера.
 *
 * Пример:
 * ```php
 * $rule = new RegexLeakOutputRule(
 *     'output.secret.bearer',
 *     'Output may disclose bearer token.',
 *     '/bearer\\s+[a-z0-9._\\-]{20,}/iu',
 *     '[REDACTED_SECRET]',
 *     'output.secrets',
 *     RuleSeverityEnum::HIGH,
 *     'Низкий риск: bearer-токены не должны выводиться пользователю.'
 * );
 * ```
 */
class RegexLeakOutputRule implements OutputDetectorRuleInterface
{
    /**
     * @param string           $code              Машиночитаемый код нарушения и ruleId.
     * @param string           $reason            Описание нарушения.
     * @param string           $pattern           Regex для поиска утечки.
     * @param string           $replacement       Строка для редактирования найденных фрагментов.
     * @param string           $group             Группа правила для отключения пачкой.
     * @param RuleSeverityEnum $severity          Уровень уверенности/критичности.
     * @param string           $falsePositiveRisk Описание риска ложного срабатывания.
     */
    public function __construct(
        private readonly string $code,
        private readonly string $reason,
        private readonly string $pattern,
        private readonly string $replacement,
        private readonly string $group = 'output.secrets',
        private readonly RuleSeverityEnum $severity = RuleSeverityEnum::MEDIUM,
        private readonly string $falsePositiveRisk = 'Regex redaction may mask explanatory text; keep patterns narrow.'
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
    public function apply(string $text): OutputRuleResultDto
    {
        $matched = [];
        $isMatch = preg_match($this->pattern, $text, $matched);
        if ($isMatch !== 1) {
            return (new OutputRuleResultDto())
                ->setText($text)
                ->setChanged(false)
                ->setViolation(null);
        }

        $redacted = preg_replace($this->pattern, $this->replacement, $text);
        $safeText = is_string($redacted) ? $redacted : $text;

        $fragment = '';
        if (isset($matched[0]) && is_string($matched[0])) {
            $fragment = mb_substr($matched[0], 0, 240, 'UTF-8');
        }

        $violation = (new OutputViolationDto())
            ->setCode($this->code)
            ->setReason($this->reason)
            ->setMatchedFragment($fragment)
            ->setReplacement($this->replacement);

        return (new OutputRuleResultDto())
            ->setText($safeText)
            ->setChanged($safeText !== $text)
            ->setViolation($violation);
    }
}
