<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe;

use app\modules\neuron\classes\safe\contracts\OutputDetectorRuleInterface;
use app\modules\neuron\classes\safe\dto\OutputSafeResultDto;

/**
 * Фасад выходной защиты сообщений, генерируемых LLM.
 *
 * Стратегия: гибридная — опасные фрагменты редактируются, нарушения возвращаются
 * вызывающему коду для сигнализации (лог/метрика/событие).
 */
class OutputSafe
{
    /**
     * @var list<OutputDetectorRuleInterface>
     */
    private array $detectorRules = [];

    /**
     * @param list<OutputDetectorRuleInterface> $detectorRules Набор правил проверки.
     */
    public function __construct(array $detectorRules = [])
    {
        $this->setDetectorRules($detectorRules);
    }

    /**
     * Полностью заменяет набор правил проверки.
     *
     * @param list<OutputDetectorRuleInterface> $rules Правила проверки.
     */
    public function setDetectorRules(array $rules): self
    {
        $this->detectorRules = [];
        foreach ($rules as $rule) {
            $this->addDetectorRule($rule);
        }
        return $this;
    }

    /**
     * Добавляет одно правило проверки.
     */
    public function addDetectorRule(OutputDetectorRuleInterface $rule): self
    {
        $this->detectorRules[] = $rule;
        return $this;
    }

    /**
     * Применяет все правила проверки/редактирования к тексту.
     *
     * @param string $text Текст ответа LLM.
     *
     * @return OutputSafeResultDto Результат с безопасным текстом и списком нарушений.
     */
    public function sanitize(string $text): OutputSafeResultDto
    {
        $current = $text;
        $result = (new OutputSafeResultDto())->setSafeText($text);

        foreach ($this->detectorRules as $rule) {
            $ruleResult = $rule->apply($current);
            $current = $ruleResult->getText();
            if ($ruleResult->getViolation() !== null) {
                $result->addViolation($ruleResult->getViolation());
            }
        }

        return $result->setSafeText($current);
    }
}
