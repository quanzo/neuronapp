<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Вспомогательные методы для преобразования значений опций компонентов.
 */
class OptionsHelper
{
    /**
     * Преобразует значение опции в boolean.
     *
     * true: значение равно 1, true или строке 'true'.
     * false: опция не задана (null), 0, false, строка 'false' или любое иное значение.
     *
     * @param mixed $option Значение опции (из getOptions() или getOption).
     */
    public static function toBool(mixed $option): bool
    {
        if ($option === 1 || $option === true) {
            return true;
        }
        if ($option === 'true') {
            return true;
        }
        return false;
    }
}
