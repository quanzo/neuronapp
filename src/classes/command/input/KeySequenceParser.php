<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command\input;

use app\modules\neuron\classes\dto\tui\KeyEventDto;

/**
 * Парсер клавиатурных последовательностей для TUI.
 *
 * Преобразует входной поток (символы UTF-8 и ESC-последовательности) в нормализованные события.
 * Класс отвечает только за преобразование «байты → событие», и не изменяет состояние приложения.
 *
 * Примечания по терминалу:
 * - обычный текст приходит как UTF-8 символы;
 * - управляющие клавиши приходят как одиночные байты (например, Ctrl+C = 0x03);
 * - специальные клавиши/мышь приходят как ESC-последовательности;
 * - bracketed paste оборачивает вставку в ESC[200~ ... ESC[201~ и позволяет корректно читать многострочный ввод.
 *
 * Поддержка:
 * - стрелки (ESC [ A/B/C/D)
 * - PageUp/PageDown (ESC [ 5~/6~)
 * - Tab, Enter, Backspace, Ctrl+C
 * - Home/End/Delete (варианты ESC[H/F и ESC[1~/4~/3~)
 * - Toggle mouse-mode (F2 как ESC O Q и/или ESC[12~)
 * - X10 mouse reporting (ESC [ M Cb Cx Cy) — отдаётся как событие мыши, если включено в терминале
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
    private const BRACKETED_PASTE_START = "\033[200~";
    private const BRACKETED_PASTE_END = "\033[201~";

    /**
     * @param Utf8CharReader $reader Читатель одного UTF-8 символа из потока
     */
    public function __construct(private readonly Utf8CharReader $reader)
    {
    }

    /**
     * Считывает одно событие клавиатуры из потока.
     *
     * Важно: метод рассчитан на non-blocking поток. Если данных нет — вернёт `null`.
     * Никаких побочных эффектов (например, изменения `stty`) здесь быть не должно.
     *
     * @param resource $stdin Ресурс, совместимый с `fgetc()` (обычно `php://stdin`)
     * @return KeyEventDto|null `null`, если данных нет или последовательность неполная/не распознана
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
     * Здесь обрабатываются:
     * - CSI-последовательности (ESC [ ...)
     * - bracketed paste (ESC[200~ ... ESC[201~)
     * - X10 mouse reporting (ESC[M ...)
     * - альтернативные варианты функциональных клавиш (например, ESC O Q для F2)
     *
     * @param resource $stdin
     * @return KeyEventDto|null Событие или `null`, если последовательность не распознана/неполная
     */
    private function readEscapeSequenceEvent($stdin): ?KeyEventDto
    {
        $next = $this->reader->readChar($stdin);
        if ($next === 'O') {
            $third = $this->reader->readChar($stdin);
            if ($third === 'Q') {
                return KeyEventDto::toggleMouseMode();
            }
            return null;
        }

        if ($next !== '[') {
            return null;
        }

        $third = $this->reader->readChar($stdin);
        if ($third === null) {
            return null;
        }

        if ($third === 'M') {
            $mouse = $this->readX10MouseEvent($stdin);
            return $mouse;
        }

        if (ctype_digit($third)) {
            $seq = $third;
            // Считываем до '~' (например: 200~, 201~, 5~, 6~).
            for ($i = 0; $i < 8; $i++) {
                $ch = $this->reader->readChar($stdin);
                if ($ch === null) {
                    return null;
                }
                $seq .= $ch;
                if ($ch === '~') {
                    break;
                }
            }

            if ($seq === '200~') {
                $text = $this->readBracketedPastePayload($stdin);
                return $text === null ? null : KeyEventDto::paste($text);
            }

            if ($seq === '201~') {
                return null;
            }

            if ($seq === '5~' || $seq === '6~') {
                return $seq === '5~' ? KeyEventDto::pageUp() : KeyEventDto::pageDown();
            }

            if ($seq === '3~') {
                return KeyEventDto::delete();
            }

            if ($seq === '1~') {
                return KeyEventDto::home();
            }

            if ($seq === '4~') {
                return KeyEventDto::end();
            }

            if ($seq === '12~') {
                return KeyEventDto::toggleMouseMode();
            }

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
            'H' => KeyEventDto::home(),
            'F' => KeyEventDto::end(),
            default => null,
        };
    }

    /**
     * Читает X10 mouse-event (ESC[M Cb Cx Cy).
     *
     * Координаты X/Y приходят в виде байтов со смещением +32 (см. xterm protocol).
     *
     * @param resource $stdin
     * @return KeyEventDto|null `null`, если событие неполное/некорректное
     */
    private function readX10MouseEvent($stdin): ?KeyEventDto
    {
        $cb = fgetc($stdin);
        $cx = fgetc($stdin);
        $cy = fgetc($stdin);
        if ($cb === false || $cx === false || $cy === false) {
            return null;
        }

        $button = ord($cb) - 32;
        $x = ord($cx) - 32;
        $y = ord($cy) - 32;
        if ($x < 1 || $y < 1) {
            return null;
        }

        return KeyEventDto::mouse($button, $x, $y);
    }

    /**
     * Читает полезную нагрузку bracketed paste до последовательности завершения.
     *
     * Ограничения:
     * - внутри вставки могут быть любые байты/строки, включая переводы строк;
     * - метод завершает чтение строго по маркеру конца `ESC[201~`;
     * - чтобы не зависнуть в случае неконсистентного потока, используется deadline.
     *
     * Нормализация:
     * - CRLF и CR приводятся к LF, чтобы далее все компоненты TUI работали с одним форматом переводов строк.
     *
     * @param resource $stdin
     * @return string|null Текст вставки без конечного маркера либо `null` по таймауту
     */
    private function readBracketedPastePayload($stdin): ?string
    {
        $buf = '';
        $end = self::BRACKETED_PASTE_END;
        $endLen = strlen($end);
        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline) {
            $ch = fgetc($stdin);
            if ($ch === false) {
                usleep(1000);
                continue;
            }
            $buf .= $ch;

            if (strlen($buf) >= $endLen && substr($buf, -$endLen) === $end) {
                $payload = substr($buf, 0, -$endLen);
                // Нормализуем CRLF/CR в LF.
                $payload = (string) preg_replace("/\\r\\n|\\r/", "\n", $payload);
                return $payload;
            }
        }

        return null;
    }
}
