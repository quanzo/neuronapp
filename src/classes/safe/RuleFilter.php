<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe;

use app\modules\neuron\classes\safe\contracts\SafeRuleInterface;

/**
 * Фильтр safe-правил по `ruleId` и `group`.
 *
 * Класс отделяет политику включения/отключения от самих правил. Это позволяет
 * расширять набор правил без изменения фасадов `InputSafe` и `OutputSafe`.
 *
 * Пример:
 * ```php
 * $enabledRules = (new RuleFilter(['input.prompt.reset_ru'], []))->filter($rules);
 * ```
 */
class RuleFilter
{
    /**
     * @param list<string> $disabledRuleIds Идентификаторы правил, отключённых в конфиге.
     * @param list<string> $disabledGroups  Группы правил, отключённые в конфиге.
     */
    public function __construct(
        private readonly array $disabledRuleIds = [],
        private readonly array $disabledGroups = []
    ) {
    }

    /**
     * Возвращает только включённые правила.
     *
     * @template T of SafeRuleInterface
     *
     * @param list<T> $rules Правила до фильтрации.
     *
     * @return list<T> Правила, не отключённые по id или группе.
     */
    public function filter(array $rules): array
    {
        $result = [];

        foreach ($rules as $rule) {
            $metadata = $rule->getMetadata();
            if (in_array($metadata->getRuleId(), $this->disabledRuleIds, true)) {
                continue;
            }
            if (in_array($metadata->getGroup(), $this->disabledGroups, true)) {
                continue;
            }

            $result[] = $rule;
        }

        return $result;
    }
}
