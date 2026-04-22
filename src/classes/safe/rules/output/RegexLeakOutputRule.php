<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\output;

use app\modules\neuron\classes\safe\contracts\OutputDetectorRuleInterface;
use app\modules\neuron\classes\safe\dto\OutputRuleResultDto;
use app\modules\neuron\classes\safe\dto\OutputViolationDto;

/**
 * Универсальное regex-правило утечки в выходном тексте LLM.
 */
class RegexLeakOutputRule implements OutputDetectorRuleInterface
{
    /**
     * @param string $code Машиночитаемый код нарушения.
     * @param string $reason Описание нарушения.
     * @param string $pattern Regex для поиска утечки.
     * @param string $replacement Строка для редактирования найденных фрагментов.
     */
    public function __construct(
        private readonly string $code,
        private readonly string $reason,
        private readonly string $pattern,
        private readonly string $replacement
    ) {
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
