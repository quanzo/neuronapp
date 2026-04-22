<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\contracts;

/**
 * Контракт правила очистки входного текста перед отправкой в LLM.
 */
interface InputSanitizerRuleInterface
{
    /**
     * Возвращает очищенную версию входного текста.
     *
     * @param string $text Сырой входной текст.
     *
     * @return string Очищенный текст.
     */
    public function sanitize(string $text): string;
}
