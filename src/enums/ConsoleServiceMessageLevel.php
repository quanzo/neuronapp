<?php

declare(strict_types=1);

namespace app\modules\neuron\enums;

/**
 * Уровень сервисного сообщения консольного вывода.
 *
 * Используется для рендеринга в md/txt: plain — без тегов, info — {@see <info>},
 * comment — {@see <comment>}.
 */
enum ConsoleServiceMessageLevel: string
{
    case Plain = 'plain';
    case Info = 'info';
    case Comment = 'comment';
}
