<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputDetectorRuleInterface;
use app\modules\neuron\classes\safe\dto\InputViolationDto;

/**
 * Универсальное regex-правило обнаружения prompt-injection признаков.
 */
class RegexInjectionInputRule implements InputDetectorRuleInterface
{
    /**
     * @param string $code Машиночитаемый код нарушения.
     * @param string $reason Описание нарушения.
     * @param string $pattern PCRE-паттерн.
     */
    public function __construct(
        private readonly string $code,
        private readonly string $reason,
        private readonly string $pattern
    ) {
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
