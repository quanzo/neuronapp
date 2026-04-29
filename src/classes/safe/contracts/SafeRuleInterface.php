<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\contracts;

use app\modules\neuron\classes\safe\dto\RuleMetadataDto;

/**
 * Базовый контракт safe-правила с метаданными.
 *
 * Все input/output/tool правила должны возвращать стабильный `ruleId`, `group`
 * и `severity`, чтобы их можно было отключать через конфигурацию без изменения
 * PHP-кода.
 *
 * Пример:
 * ```php
 * $ruleId = $rule->getMetadata()->getRuleId();
 * ```
 */
interface SafeRuleInterface
{
    /**
     * Возвращает метаданные правила.
     *
     * @return RuleMetadataDto DTO с ruleId/group/severity и описанием.
     */
    public function getMetadata(): RuleMetadataDto;
}
