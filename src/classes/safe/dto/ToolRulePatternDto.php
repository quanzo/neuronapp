<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\dto;

use app\modules\neuron\classes\safe\contracts\SafeRuleInterface;

/**
 * DTO regex-политики для tool-команд.
 *
 * В отличие от `InputSafe`/`OutputSafe`, tool-политика не анализирует текст
 * LLM-сообщения. Она предоставляет готовый regex для `BashTool::blockedPatterns`.
 *
 * Пример:
 * ```php
 * $rule = (new ToolRulePatternDto())
 *     ->setMetadata($metadata)
 *     ->setPattern('/\\/proc\\/self\\/environ/i');
 * ```
 */
class ToolRulePatternDto implements SafeRuleInterface
{
    /** Метаданные правила для отключения по id/group. */
    private RuleMetadataDto $metadata;

    /** PCRE-паттерн, который будет добавлен в blockedPatterns инструмента. */
    private string $pattern = '';

    public function __construct()
    {
        $this->metadata = new RuleMetadataDto();
    }

    /**
     * Возвращает метаданные tool-правила.
     *
     * @return RuleMetadataDto Метаданные с ruleId/group/severity.
     */
    public function getMetadata(): RuleMetadataDto
    {
        return $this->metadata;
    }

    /**
     * Устанавливает метаданные tool-правила.
     *
     * @param RuleMetadataDto $metadata DTO метаданных.
     *
     * @return self Текущий DTO для fluent-цепочки.
     */
    public function setMetadata(RuleMetadataDto $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Возвращает PCRE-паттерн блокировки.
     *
     * @return string Паттерн для `BashTool::blockedPatterns`.
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Устанавливает PCRE-паттерн блокировки.
     *
     * @param string $pattern Паттерн для `preg_match`.
     *
     * @return self Текущий DTO для fluent-цепочки.
     */
    public function setPattern(string $pattern): self
    {
        $this->pattern = $pattern;
        return $this;
    }
}
