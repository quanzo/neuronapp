<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces;

/**
 * Интерфейс списка заданий Todo.
 *
 * Позволяет добавлять задания, извлекать их по принципу FIFO
 * и получать набор опций списка.
 */
interface ITodoList
{
    /**
     * Добавляет одно или несколько заданий в список.
     *
     * @param ITodo ...$todos Экземпляры заданий для добавления.
     */
    public function pushTodo(ITodo ...$todos): void;

    /**
     * Извлекает одно задание из начала списка по принципу FIFO.
     *
     * @return ITodo|null Задание либо null, если список пуст.
     */
    public function popTodo(): ?ITodo;

    /**
     * Возвращает массив заданий (копию списка) для итерации без изменения очереди.
     *
     * @return list<ITodo>
     */
    public function getTodos(): array;

    /**
     * Возвращает массив опций списка заданий.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array;
}

