<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputSanitizerRuleInterface;
use app\modules\neuron\classes\safe\dto\RuleMetadataDto;
use app\modules\neuron\classes\safe\enums\RuleSeverityEnum;

/**
 * Ограничивает максимальную длину входного текста.
 *
 * RuleId: `input.sanitize.max_length`.
 * Group: `input.sanitize`.
 * Severity: `medium`.
 * Нарушение: аномально длинный вход, который может быть payload stuffing или
 * попыткой перегрузить контекст.
 * False-positive риск: средний; длинные легитимные запросы будут обрезаны.
 */
class MaxLengthInputRule implements InputSanitizerRuleInterface
{
    /**
     * @param int $maxChars Максимальная длина текста после очистки.
     */
    public function __construct(private readonly int $maxChars = 20000)
    {
    }

    /**
     * Возвращает метаданные sanitize-правила.
     *
     * @return RuleMetadataDto Описание правила для фильтрации и документации.
     */
    public function getMetadata(): RuleMetadataDto
    {
        return (new RuleMetadataDto())
            ->setRuleId('input.sanitize.max_length')
            ->setGroup('input.sanitize')
            ->setSeverity(RuleSeverityEnum::MEDIUM)
            ->setDescription('Limits maximum LLM input length after sanitization.')
            ->setFalsePositiveRisk('Medium: long legitimate context may be truncated.');
    }

    /**
     * @inheritDoc
     */
    public function sanitize(string $text): string
    {
        if ($this->maxChars <= 0) {
            return $text;
        }

        if (mb_strlen($text, 'UTF-8') <= $this->maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $this->maxChars, 'UTF-8');
    }
}
