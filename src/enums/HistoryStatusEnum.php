<?php
declare(strict_types=1);

namespace app\modules\neuron\enums;

/**
 * Статусы
 */
enum HistoryStatusEnum: int {
    case DISABLE = 0;
    case ACTIVE = 1;
}