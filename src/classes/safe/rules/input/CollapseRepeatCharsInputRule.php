<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputSanitizerRuleInterface;
use app\modules\neuron\classes\safe\dto\RuleMetadataDto;
use app\modules\neuron\classes\safe\enums\RuleSeverityEnum;

/**
 * Схлопывает аномально длинные последовательности одинаковых символов.
 *
 * RuleId: `input.sanitize.repeat_chars`.
 * Group: `input.sanitize`.
 * Severity: `medium`.
 * Нарушение: длинные повторы символов, используемые для BoN/obfuscation
 * вариантов и шумовых prompt-injection сообщений.
 * False-positive риск: средний; декоративные строки будут укорочены.
 */
class CollapseRepeatCharsInputRule implements InputSanitizerRuleInterface
{
    /**
     * @param int $maxRepeat Максимально допустимая длина повтора одного символа.
     */
    public function __construct(private readonly int $maxRepeat = 5)
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
            ->setRuleId('input.sanitize.repeat_chars')
            ->setGroup('input.sanitize')
            ->setSeverity(RuleSeverityEnum::MEDIUM)
            ->setDescription('Collapses long repeated character runs before LLM input.')
            ->setFalsePositiveRisk('Medium: decorative separators may be shortened.');
    }

    /**
     * @inheritDoc
     */
    public function sanitize(string $text): string
    {
        if ($this->maxRepeat < 2) {
            return $text;
        }

        $replacement = str_repeat('$1', $this->maxRepeat);
        $pattern = '/(.)\1{' . $this->maxRepeat . ',}/u';
        $collapsed = preg_replace($pattern, $replacement, $text);

        return is_string($collapsed) ? $collapsed : $text;
    }
}
