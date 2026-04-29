<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe;

use app\modules\neuron\classes\safe\contracts\InputDetectorRuleInterface;
use app\modules\neuron\classes\safe\contracts\InputSanitizerRuleInterface;
use app\modules\neuron\classes\safe\exceptions\InputSafetyViolationException;

/**
 * Фасад входной защиты сообщений, отправляемых в LLM.
 *
 * Этапы:
 * 1. Санитизация текста.
 * 2. Детекция признаков prompt-injection/jailbreak.
 *
 * Пример:
 * ```php
 * $safeText = $inputSafe->sanitizeAndAssert($userText);
 * ```
 */
class InputSafe
{
    /**
     * Правила, которые изменяют входной текст без блокировки запроса.
     *
     * @var list<InputSanitizerRuleInterface>
     */
    private array $sanitizerRules = [];

    /**
     * Правила, которые анализируют очищенный текст и могут заблокировать запрос.
     *
     * @var list<InputDetectorRuleInterface>
     */
    private array $detectorRules = [];

    /**
     * @param list<InputSanitizerRuleInterface> $sanitizerRules Набор правил санитизации.
     * @param list<InputDetectorRuleInterface>  $detectorRules Набор правил детекции.
     *
     * Пример:
     * ```php
     * $safe = new InputSafe([$sanitizeRule], [$detectRule]);
     * ```
     */
    public function __construct(array $sanitizerRules = [], array $detectorRules = [])
    {
        $this->setSanitizerRules($sanitizerRules);
        $this->setDetectorRules($detectorRules);
    }

    /**
     * Полностью заменяет набор правил санитизации.
     *
     * @param list<InputSanitizerRuleInterface> $rules Правила санитизации.
     *
     * @return self Текущий экземпляр для fluent-цепочки.
     */
    public function setSanitizerRules(array $rules): self
    {
        $this->sanitizerRules = [];
        foreach ($rules as $rule) {
            $this->addSanitizerRule($rule);
        }
        return $this;
    }

    /**
     * Добавляет одно правило санитизации.
     *
     * @param InputSanitizerRuleInterface $rule Правило очистки входного текста.
     *
     * @return self Текущий экземпляр для fluent-цепочки.
     */
    public function addSanitizerRule(InputSanitizerRuleInterface $rule): self
    {
        $this->sanitizerRules[] = $rule;
        return $this;
    }

    /**
     * Полностью заменяет набор правил детекции.
     *
     * @param list<InputDetectorRuleInterface> $rules Правила детекции.
     *
     * @return self Текущий экземпляр для fluent-цепочки.
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
     * Добавляет одно правило детекции.
     *
     * @param InputDetectorRuleInterface $rule Правило блокирующей проверки.
     *
     * @return self Текущий экземпляр для fluent-цепочки.
     */
    public function addDetectorRule(InputDetectorRuleInterface $rule): self
    {
        $this->detectorRules[] = $rule;
        return $this;
    }

    /**
     * Выполняет только этап очистки текста.
     *
     * @param string $text Сырой входной текст.
     *
     * @return string Очищенный текст.
     */
    public function sanitize(string $text): string
    {
        $result = $text;
        foreach ($this->sanitizerRules as $rule) {
            $result = $rule->sanitize($result);
        }
        return $result;
    }

    /**
     * Выполняет только этап детекции опасного содержимого.
     *
     * @param string $text Очищенный входной текст.
     *
     * @throws InputSafetyViolationException Когда обнаружен признак атаки.
     */
    public function assertSafe(string $text): void
    {
        foreach ($this->detectorRules as $rule) {
            $violation = $rule->detect($text);
            if ($violation !== null) {
                throw new InputSafetyViolationException($violation);
            }
        }
    }

    /**
     * Выполняет оба этапа: очистку и проверку.
     *
     * @param string $text Сырой входной текст.
     *
     * @return string Безопасный очищенный текст.
     *
     * @throws InputSafetyViolationException Когда обнаружен признак атаки.
     */
    public function sanitizeAndAssert(string $text): string
    {
        $sanitized = $this->sanitize($text);
        $this->assertSafe($sanitized);
        return $sanitized;
    }
}
