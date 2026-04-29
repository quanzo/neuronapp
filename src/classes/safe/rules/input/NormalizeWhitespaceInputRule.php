<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputSanitizerRuleInterface;
use app\modules\neuron\classes\safe\dto\RuleMetadataDto;
use app\modules\neuron\classes\safe\enums\RuleSeverityEnum;

/**
 * Нормализует пробелы и переносы строк во входном тексте.
 *
 * RuleId: `input.sanitize.whitespace`.
 * Group: `input.sanitize`.
 * Severity: `medium`.
 * Нарушение: чрезмерные пробелы/переносы, которые часто используются для
 * простого обхода regex-детекции.
 * False-positive риск: средний; форматирование пользовательского текста может
 * стать компактнее, но смысл сохраняется.
 */
class NormalizeWhitespaceInputRule implements InputSanitizerRuleInterface
{
    /**
     * Возвращает метаданные sanitize-правила.
     *
     * @return RuleMetadataDto Описание правила для фильтрации и документации.
     */
    public function getMetadata(): RuleMetadataDto
    {
        return (new RuleMetadataDto())
            ->setRuleId('input.sanitize.whitespace')
            ->setGroup('input.sanitize')
            ->setSeverity(RuleSeverityEnum::MEDIUM)
            ->setDescription('Normalizes repeated spaces and empty lines before LLM input.')
            ->setFalsePositiveRisk('Medium: intentional formatting may be compacted.');
    }

    /**
     * @inheritDoc
     */
    public function sanitize(string $text): string
    {
        $normalized = preg_replace('/[ \t]{2,}/u', ' ', $text);
        $normalized = is_string($normalized) ? $normalized : $text;

        $normalized2 = preg_replace('/\n{3,}/u', "\n\n", $normalized);
        $normalized2 = is_string($normalized2) ? $normalized2 : $normalized;

        return trim($normalized2);
    }
}
