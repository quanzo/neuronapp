<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputSanitizerRuleInterface;

/**
 * Схлопывает аномально длинные последовательности одинаковых символов.
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
