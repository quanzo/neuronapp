<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputSanitizerRuleInterface;

/**
 * Нормализует пробелы и переносы строк во входном тексте.
 */
class NormalizeWhitespaceInputRule implements InputSanitizerRuleInterface
{
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
