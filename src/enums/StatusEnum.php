<?php
declare(strict_types=1);

namespace app\modules\neuron\enums;

/**
 * Статусы
 */
enum StatusEnum: int
{
    case DISABLE = 0;
    case ACTIVE = 1;
}