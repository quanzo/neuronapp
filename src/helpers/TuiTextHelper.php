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
        $current = mb_strwidth($str);
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
        while (mb_strwidth($line) > $maxWidth) {
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
}
