<?php

namespace app\modules\neron\interfaces;

/**
 * Интерфейс, который должны реализовывать все команды приложения.
 */
interface CommandInterface
{
    /**
     * Выполняет команду.
     *
     * @param array $options Ассоциативный массив опций (ключ => значение)
     * @return void
     */
    public function execute(array $options): void;
}