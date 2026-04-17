<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces\tui\view;

/**
 * Контракт блока (виджета) для rich-вывода в TUI.
 *
 * Блок — это структурная единица вывода (таблица, код, панель и т.д.), которая
 * форматируется в строки с учётом ширины терминала и темы.
 *
 * Пример использования:
 *
 * ```php
 * final class TextBlockDto implements TuiBlockInterface { ... }
 * ```
 */
interface TuiBlockInterface
{
    /**
     * Возвращает тип блока (для форматтера/отладки).
     */
    public function getType(): string;
}
