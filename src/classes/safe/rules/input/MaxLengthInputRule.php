<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputSanitizerRuleInterface;

/**
 * Ограничивает максимальную длину входного текста.
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
