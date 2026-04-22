<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\contracts;

use app\modules\neuron\classes\safe\dto\InputViolationDto;

/**
 * Контракт правила детекции опасных входных инструкций для LLM.
 */
interface InputDetectorRuleInterface
{
    /**
     * Проверяет текст на наличие признаков атаки/манипуляции.
     *
     * @param string $text Очищенный входной текст.
     *
     * @return InputViolationDto|null DTO нарушения или null, если правило не сработало.
     */
    public function detect(string $text): ?InputViolationDto;
}
