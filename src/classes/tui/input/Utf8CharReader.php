<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\tui\input;

/**
 * Читает один UTF-8 символ из потока (не блокируя логику вызывающего кода).
 *
 * Используется на низком уровне TUI-ввода совместно с парсером ESC-последовательностей,
 * чтобы получать данные «по символам», а не «по строкам».
 *
 * Пример использования:
 *
 * ```php
 * $reader = new Utf8CharReader();
 * $char = $reader->readChar($stdin);
 * ```
 */
final class Utf8CharReader
{
    /**
     * Читает один UTF-8 символ из потока.
     *
     * Важно: метод рассчитан на использование с non-blocking stream. Если данных нет — возвращает `null`.
     *
     * @param resource $stream
     * @return string|null Один UTF-8 символ (1–4 байта) или `null`, если данных нет/последовательность неполная
     */
    public function readChar($stream): ?string
    {
        $byte = fgetc($stream);
        if ($byte === false) {
            return null;
        }

        $ord = ord($byte);
        if ($ord < 0x80) {
            return $byte;
        }

        if ($ord < 0xE0) {
            $second = fgetc($stream);
            return $second === false ? null : $byte . $second;
        }

        if ($ord < 0xF0) {
            $second = fgetc($stream);
            $third = fgetc($stream);
            return ($second === false || $third === false) ? null : $byte . $second . $third;
        }

        $second = fgetc($stream);
        $third = fgetc($stream);
        $fourth = fgetc($stream);
        return ($second === false || $third === false || $fourth === false) ? null : $byte . $second . $third . $fourth;
    }
}
