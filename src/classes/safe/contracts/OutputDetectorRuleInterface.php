<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\contracts;

use app\modules\neuron\classes\safe\dto\OutputRuleResultDto;

/**
 * Контракт правила проверки и редактирования выходного текста LLM.
 */
interface OutputDetectorRuleInterface extends SafeRuleInterface
{
    /**
     * Применяет правило к выходному тексту.
     *
     * @param string $text Исходный текст ответа LLM.
     *
     * @return OutputRuleResultDto Результат применения правила.
     */
    public function apply(string $text): OutputRuleResultDto;
}
