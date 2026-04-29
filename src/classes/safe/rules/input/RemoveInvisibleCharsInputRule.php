<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputSanitizerRuleInterface;
use app\modules\neuron\classes\safe\dto\RuleMetadataDto;
use app\modules\neuron\classes\safe\enums\RuleSeverityEnum;

/**
 * Удаляет невидимые и непечатные Unicode-символы из входного текста.
 *
 * RuleId: `input.sanitize.invisible_chars`.
 * Group: `input.sanitize`.
 * Severity: `high`.
 * Нарушение: управляющие ASCII-символы, bidi/zero-width символы и BOM,
 * которые могут использоваться для Unicode-smuggling.
 * False-positive риск: низкий; сохраняются обычные пробелы, табы и переносы строк.
 */
class RemoveInvisibleCharsInputRule implements InputSanitizerRuleInterface
{
    /**
     * Возвращает метаданные sanitize-правила.
     *
     * @return RuleMetadataDto Описание правила для фильтрации и документации.
     */
    public function getMetadata(): RuleMetadataDto
    {
        return (new RuleMetadataDto())
            ->setRuleId('input.sanitize.invisible_chars')
            ->setGroup('input.sanitize')
            ->setSeverity(RuleSeverityEnum::HIGH)
            ->setDescription('Removes invisible/control Unicode characters before LLM input.')
            ->setFalsePositiveRisk('Low: regular whitespace is preserved.');
    }

    /**
     * @inheritDoc
     */
    public function sanitize(string $text): string
    {
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text);
        $sanitized = is_string($sanitized) ? $sanitized : $text;

        // Zero-width/format chars часто используются для обфускации инъекций.
        $sanitized2 = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]+/u', '', $sanitized);

        return is_string($sanitized2) ? $sanitized2 : $sanitized;
    }
}
