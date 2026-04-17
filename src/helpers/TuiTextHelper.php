<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Хелпер для текстовых операций в TUI (перенос и выравнивание).
 *
 * Здесь собраны функции, не привязанные к объектам рендера/состояния.
 *
 * Пример использования:
 *
 * ```php
 * $chunks = TuiTextHelper::splitByWidth('Привет мир', 5);
 * $padded = TuiTextHelper::padString('ok', 10);
 * ```
 */
final class TuiTextHelper
{
    private const ANSI_REGEX = '/\033\[[0-9;]*m/';
    private const ANSI_CAPTURE_REGEX = '/(\033\[[0-9;]*m)/';
    private const ANSI_RESET = "\033[0m";

    /**
     * Дополняет строку пробелами справа до нужной ширины (в колонках терминала).
     * Учитывает ширину многобайтовых символов.
     *
     * @param string $str
     * @param int    $width
     * @return string
     */
    public static function padString(string $str, int $width): string
    {
        $current = self::visibleWidth($str);
        if ($current >= $width) {
            return $str;
        }
        return $str . str_repeat(' ', $width - $current);
    }

    /**
     * Разбивает строку на части, каждая не шире заданной ширины (в колонках).
     *
     * @param string $line
     * @param int    $maxWidth
     * @return string[]
     */
    public static function splitByWidth(string $line, int $maxWidth): array
    {
        if ($maxWidth <= 0) {
            return [$line];
        }

        $result = [];
        while (self::visibleWidth($line) > $maxWidth) {
            $pos = 0;
            $width = 0;
            $len = mb_strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $char = mb_substr($line, $i, 1);
                $charWidth = mb_strwidth($char);
                if ($width + $charWidth > $maxWidth) {
                    break;
                }
                $width += $charWidth;
                $pos++;
            }
            if ($pos === 0) {
                $pos = 1;
            }
            $result[] = mb_substr($line, 0, $pos);
            $line = mb_substr($line, $pos);
        }
        if ($line !== '') {
            $result[] = $line;
        }
        return $result;
    }

    /**
     * Преобразует историю сообщений в массив строк для отображения.
     * Учитывает перенос длинных строк и добавляет пустую строку-разделитель между сообщениями.
     *
     * @param string[] $history
     * @param int      $innerWidth Ширина внутренней области рамки (ширина терминала минус 2).
     * @return string[]
     */
    public static function buildDisplayLines(array $history, int $innerWidth): array
    {
        $lines = [];
        foreach ($history as $message) {
            $messageLines = explode("\n", (string) $message);
            foreach ($messageLines as $line) {
                foreach (self::splitByWidth((string) $line, $innerWidth) as $chunk) {
                    $lines[] = $chunk;
                }
            }
            $lines[] = '';
        }

        if (!empty($lines) && end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    /**
     * Удаляет ANSI-последовательности из строки.
     *
     * @param string $s
     * @return string
     */
    public static function stripAnsi(string $s): string
    {
        return (string) preg_replace(self::ANSI_REGEX, '', $s);
    }

    /**
     * Возвращает «видимую» ширину строки без ANSI-кодов.
     *
     * @param string $s
     * @return int
     */
    public static function visibleWidth(string $s): int
    {
        return mb_strwidth(self::stripAnsi($s));
    }

    /**
     * Обрезает строку по видимой ширине и добавляет многоточие.
     *
     * @param string $s
     * @param int    $maxWidth
     * @param string $ellipsis
     * @return string
     */
    public static function trimToWidthWithEllipsis(string $s, int $maxWidth, string $ellipsis = '…'): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        if (self::visibleWidth($s) <= $maxWidth) {
            return $s;
        }

        $ellW = mb_strwidth($ellipsis);
        $target = max(0, $maxWidth - $ellW);
        $plain = self::stripAnsi($s);
        $cut = mb_strimwidth($plain, 0, $target, '', 'UTF-8');
        return $cut . $ellipsis;
    }

    /**
     * Обрезает строку по видимой ширине, не разрывая ANSI-последовательности.
     *
     * Использовать вместо mb_strimwidth() для строк, содержащих ANSI-коды.
     *
     * @param string $s
     * @param int $maxWidth
     * @return string
     */
    public static function trimAnsiToVisibleWidth(string $s, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        if (self::visibleWidth($s) <= $maxWidth) {
            return $s;
        }

        $tokens = preg_split(self::ANSI_CAPTURE_REGEX, $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || $tokens === []) {
            return mb_strimwidth($s, 0, $maxWidth, '', 'UTF-8');
        }

        $out = '';
        $w = 0;
        $hadAnsi = false;

        foreach ($tokens as $tok) {
            if (preg_match(self::ANSI_REGEX, $tok) === 1) {
                $out .= $tok;
                $hadAnsi = true;
                continue;
            }

            $len = mb_strlen($tok);
            for ($i = 0; $i < $len; $i++) {
                $ch = mb_substr($tok, $i, 1);
                $cw = mb_strwidth($ch);
                if ($w + $cw > $maxWidth) {
                    if ($hadAnsi) {
                        $out .= self::ANSI_RESET;
                    }
                    return $out;
                }
                $out .= $ch;
                $w += $cw;
            }
        }

        if ($hadAnsi) {
            $out .= self::ANSI_RESET;
        }

        return $out;
    }
}
