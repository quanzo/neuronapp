<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputSanitizerRuleInterface;

/**
 * Удаляет невидимые и непечатные Unicode-символы из входного текста.
 */
class RemoveInvisibleCharsInputRule implements InputSanitizerRuleInterface
{
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
