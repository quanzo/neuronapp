<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces\tui;

use app\modules\neuron\classes\dto\tui\PostOutputContextDto;

/**
 * Контракт post-hook для интерактивного TUI.
 *
 * Вызывается после фактической отрисовки кадра, который включал вывод.
 * Получает исходный ввод и текст, который был предназначен для вывода.
 * Может вернуть дополнительный многострочный текст, который будет добавлен в history
 * и показан на следующем кадре.
 *
 * Пример использования:
 *
 * ```php
 * $extra = $hook->afterRender($ctx);
 * if ($extra !== null) { ... }
 * ```
 */
interface TuiPostOutputHookInterface
{
    /**
     * Вызывается после рендера кадра.
     *
     * @param PostOutputContextDto $ctx Контекст (исходный ввод + выведенный текст).
     * @return string|null Дополнительный текст для добавления в history или null.
     */
    public function afterRender(PostOutputContextDto $ctx): ?string;
}
