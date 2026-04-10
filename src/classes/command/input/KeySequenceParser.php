<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command\input;

use app\modules\neuron\classes\dto\tui\KeyEventDto;

/**
 * Парсер клавиатурных последовательностей для TUI.
 *
 * Преобразует входной поток (символы UTF-8 и ESC-последовательности) в нормализованные события.
 *
 * Поддержка:
 * - стрелки (ESC [ A/B/C/D)
 * - PageUp/PageDown (ESC [ 5~/6~)
 * - Tab, Enter, Backspace, Ctrl+C
 * - произвольный UTF-8 текст
 *
 * Пример использования:
 *
 * ```php
 * $parser = new KeySequenceParser(new Utf8CharReader());
 * $event = $parser->readEvent($stdin);
 * ```
 */
final class KeySequenceParser
{
    public function __construct(
        private readonly Utf8CharReader $reader,
    ) {
    }

    /**
     * Считывает одно событие клавиатуры из потока.
     *
     * @param resource $stdin
     * @return KeyEventDto|null null если данных нет
     */
    public function readEvent($stdin): ?KeyEventDto
    {
        $char = $this->reader->readChar($stdin);
        if ($char === null) {
            return null;
        }

        if ($char === "\x03") {
            return KeyEventDto::ctrlC();
        }

        if ($char === "\t") {
            return KeyEventDto::tab();
        }

        if ($char === "\n" || $char === "\r") {
            return KeyEventDto::enter();
        }

        if ($char === "\177" || $char === "\x7f") {
            return KeyEventDto::backspace();
        }

        if ($char === "\033") {
            return $this->readEscapeSequenceEvent($stdin);
        }

        if (mb_strlen($char) > 0) {
            return KeyEventDto::text($char);
        }

        return null;
    }

    /**
     * Читает ESC-последовательность и возвращает событие (если распознано).
     *
     * @param resource $stdin
     * @return KeyEventDto|null
     */
    private function readEscapeSequenceEvent($stdin): ?KeyEventDto
    {
        $next = $this->reader->readChar($stdin);
        if ($next !== '[') {
            return null;
        }

        $third = $this->reader->readChar($stdin);
        if ($third === null) {
            return null;
        }

        if ($third === '5' || $third === '6') {
            $fourth = $this->reader->readChar($stdin);
            if ($fourth !== '~') {
                return null;
            }
            return $third === '5' ? KeyEventDto::pageUp() : KeyEventDto::pageDown();
        }

        return match ($third) {
            'A' => KeyEventDto::arrowUp(),
            'B' => KeyEventDto::arrowDown(),
            'C' => KeyEventDto::arrowRight(),
            'D' => KeyEventDto::arrowLeft(),
            default => null,
        };
    }
}
