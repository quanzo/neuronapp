<?php

namespace app\modules\neron\interfaces;

/**
 * Интерфейс для всех компонентов строки состояния.
 * Каждый статус предоставляет текст и ANSI-код цвета.
 */
interface StatusInterface
{
    /**
     * Возвращает текст, который будет отображён в строке состояния.
     *
     * @return string
     */
    public function getText(): string;

    /**
     * Возвращает ANSI-код цвета для данного статуса.
     * Например: "\033[92m" для зелёного.
     *
     * @return string
     */
    public function getColorCode(): string;
}