<?php

namespace app\modules\neuron\classes\command\terminal;

/**
 * Управляет режимами терминала для TUI.
 *
 * Отвечает за:
 * - включение/выключение альтернативного буфера;
 * - перевод терминала в неканонический режим без эха;
 * - скрытие/показ курсора.
 * - включение/выключение bracketed paste mode (для корректной многострочной вставки);
 * - включение/выключение X10 mouse reporting (для обработки кликов/скролла внутри TUI).
 *
 * Важно: методы предназначены для использования в конструкции try/finally,
 * чтобы гарантировать восстановление терминала при любых ошибках.
 *
 * Безопасность:
 * - используем только безопасные команды `stty` без пользовательского ввода;
 * - перед изменением `stty` сохраняем текущее состояние через `stty -g` и восстанавливаем его при выходе.
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
    /**
     * Флаг того, что `enter()` был успешно выполнен (идемпотентность и безопасный `leave()`).
     */
    private bool $entered = false;

    /**
     * Снимок `stty -g` до перехода в raw-режим.
     */
    private ?string $prevStty = null;

    /**
     * Локальный флаг включения X10 mouse reporting, чтобы не дёргать ESC-последовательности повторно.
     */
    private bool $mouseX10Enabled = false;

    /**
     * Включает режимы TUI: alt-buffer, raw-input, скрывает курсор.
     *
     * Порядок важен:
     * - сначала переключаем ввод (stty), чтобы сразу корректно читать события;
     * - затем скрываем курсор и включаем alt-buffer, чтобы не «портить» основной экран;
     * - включаем bracketed paste mode для корректной обработки вставки многострочного текста.
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
        $this->enableBracketedPaste();

        $this->entered = true;
    }

    /**
     * Откатывает режимы TUI: выключает alt-buffer, возвращает canonical+echo, показывает курсор.
     * Метод идемпотентен: безопасно вызывать несколько раз.
     *
     * Даже если `enter()` завершился не полностью, метод старается максимально безопасно
     * вернуть терминал в нормальное состояние.
     *
     * @return void
     */
    public function leave(): void
    {
        if (!$this->entered) {
            // Пытаемся быть максимально безопасными даже если enter() не был вызван до конца.
            $this->disableMouseX10();
            $this->disableBracketedPaste();
            $this->disableAltBuffer();
            $this->showCursor();
            $this->enableCanonicalEcho();
            return;
        }

        $this->disableMouseX10();
        $this->disableBracketedPaste();
        $this->disableAltBuffer();
        $this->showCursor();
        $this->enableCanonicalEcho();

        $this->entered = false;
    }

    /**
     * Включает альтернативный буфер терминала.
     *
     * Используется DECSET 1049 (xterm): сохраняет экран/курсор и переключает на «виртуальный» буфер.
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
     * Используется DECRST 1049: возвращает сохранённый экран/курсор.
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
     * DECTCEM: ESC[?25l.
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
     * DECTCEM: ESC[?25h.
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
     * Это позволяет читать ввод по символам (а не по строкам) и не отображать вводимые символы автоматически.
     *
     * @return void
     */
    private function enableNonCanonicalNoEcho(): void
    {
        $this->prevStty = (string) shell_exec('stty -g 2>/dev/null');
        system('stty -icanon -echo');
    }

    /**
     * Возвращает canonical режим и echo.
     *
     * Если ранее был сохранён снимок `stty -g`, восстанавливаем его как наиболее корректный вариант.
     *
     * @return void
     */
    private function enableCanonicalEcho(): void
    {
        if ($this->prevStty !== null && $this->prevStty !== '') {
            system('stty ' . escapeshellarg($this->prevStty));
            $this->prevStty = null;
            return;
        }

        system('stty icanon echo');
    }

    /**
     * Включает bracketed paste mode.
     *
     * DECSET 2004: терминал начнёт оборачивать вставку маркерами ESC[200~ и ESC[201~.
     *
     * @return void
     */
    private function enableBracketedPaste(): void
    {
        echo "\033[?2004h";
    }

    /**
     * Выключает bracketed paste mode.
     *
     * DECRST 2004.
     *
     * @return void
     */
    private function disableBracketedPaste(): void
    {
        echo "\033[?2004l";
    }

    /**
     * Включает X10 mouse reporting (ESC[?1000h).
     *
     * Важно: при включении терминал обычно перестаёт отдавать выделение/копирование мышью
     * как нативное поведение, поэтому режим должен быть переключаемым.
     *
     * @return void
     */
    public function enableMouseX10(): void
    {
        if ($this->mouseX10Enabled) {
            return;
        }

        echo "\033[?1000h";
        $this->mouseX10Enabled = true;
    }

    /**
     * Выключает X10 mouse reporting (ESC[?1000l).
     *
     * @return void
     */
    public function disableMouseX10(): void
    {
        if (!$this->mouseX10Enabled) {
            return;
        }

        echo "\033[?1000l";
        $this->mouseX10Enabled = false;
    }
}
