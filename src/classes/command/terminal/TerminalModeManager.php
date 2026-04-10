<?php

namespace app\modules\neuron\classes\command\terminal;

/**
 * Управляет режимами терминала для TUI.
 *
 * Отвечает за:
 * - включение/выключение альтернативного буфера;
 * - перевод терминала в неканонический режим без эха;
 * - скрытие/показ курсора.
 *
 * Важно: методы предназначены для использования в конструкции try/finally,
 * чтобы гарантировать восстановление терминала при любых ошибках.
 *
 * Пример использования:
 *
 * ```php
 * $terminalMode = new TerminalModeManager();
 * $terminalMode->enter();
 * try {
 *     // ... run TUI ...
 * } finally {
 *     $terminalMode->leave();
 * }
 * ```
 */
class TerminalModeManager
{
    private bool $entered = false;

    /**
     * Включает режимы TUI: alt-buffer, raw-input, скрывает курсор.
     *
     * @return void
     */
    public function enter(): void
    {
        if ($this->entered) {
            return;
        }

        $this->enableNonCanonicalNoEcho();
        $this->hideCursor();
        $this->enableAltBuffer();

        $this->entered = true;
    }

    /**
     * Откатывает режимы TUI: выключает alt-buffer, возвращает canonical+echo, показывает курсор.
     * Метод идемпотентен: безопасно вызывать несколько раз.
     *
     * @return void
     */
    public function leave(): void
    {
        if (!$this->entered) {
            // Пытаемся быть максимально безопасными даже если enter() не был вызван до конца.
            $this->disableAltBuffer();
            $this->showCursor();
            $this->enableCanonicalEcho();
            return;
        }

        $this->disableAltBuffer();
        $this->showCursor();
        $this->enableCanonicalEcho();

        $this->entered = false;
    }

    /**
     * Включает альтернативный буфер терминала.
     *
     * @return void
     */
    private function enableAltBuffer(): void
    {
        echo "\033[?1049h";
    }

    /**
     * Выключает альтернативный буфер терминала.
     *
     * @return void
     */
    private function disableAltBuffer(): void
    {
        echo "\033[?1049l";
    }

    /**
     * Скрывает курсор.
     *
     * @return void
     */
    private function hideCursor(): void
    {
        echo "\033[?25l";
    }

    /**
     * Показывает курсор.
     *
     * @return void
     */
    private function showCursor(): void
    {
        echo "\033[?25h";
    }

    /**
     * Переводит терминал в неканонический режим и отключает echo.
     *
     * @return void
     */
    private function enableNonCanonicalNoEcho(): void
    {
        system('stty -icanon -echo');
    }

    /**
     * Возвращает canonical режим и echo.
     *
     * @return void
     */
    private function enableCanonicalEcho(): void
    {
        system('stty icanon echo');
    }
}
