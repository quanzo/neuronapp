<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces;

/**
 * Интерфейс одного задания Todo.
 *
 * Представляет текстовую задачу в списке заданий.
 */
interface ITodo
{
    /**
     * Возвращает полный текст задания.
     */
    public function getTodo(): string;
}

