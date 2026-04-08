<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function strtolower;
use function trim;

/**
 * Единая точка истины для нормализации флага `completed`.
 *
 * Нужна для синхронизации orchestrator, tools, тестов и документации.
 *
 * Пример:
 * <code>
 * $normalized = TodoCompletedStatusHelper::normalize('исполнено'); // 1
 * $description = TodoCompletedStatusHelper::describeAllowedValues();
 * </code>
 */
final class TodoCompletedStatusHelper
{
    /**
     * @var list<string>
     */
    public const DONE_VALUES = ['done', '1', 'true', 'исполнено'];

    /**
     * @var list<string>
     */
    public const NOT_DONE_VALUES = ['not_done', '0', 'false', 'не исполнено', 'неисполнено'];

    /**
     * Нормализует входное значение в `1|0|null`.
     *
     * @param mixed $raw int/bool/string-значение флага completed.
     *
     * @return int|null 1 = выполнено, 0 = не выполнено, null = нераспознанное значение.
     */
    public static function normalize(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? 1 : 0;
        }

        if (is_bool($raw)) {
            return $raw ? 1 : 0;
        }

        if (!is_string($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));

        if (in_array($value, self::DONE_VALUES, true)) {
            return 1;
        }

        if (in_array($value, self::NOT_DONE_VALUES, true)) {
            return 0;
        }

        return null;
    }

    /**
     * Возвращает текстовое описание допустимых строковых значений.
     *
     * @return string Строка для CLI, docs и ошибок в tool-ответах.
     */
    public static function describeAllowedValues(): string
    {
        return 'done/not_done, 1/0, true/false, исполнено/не исполнено';
    }
}
