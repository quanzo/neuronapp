<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Хелпер для операций над буфером ввода TUI.
 *
 * Буфер хранится как UTF-8 строка. Позиция курсора — смещение в символах (не байтах).
 *
 * Пример использования:
 *
 * ```php
 * [$buf, $offset] = TuiInputBufferHelper::insert($buf, $offset, 'я');
 * [$line, $col] = TuiInputBufferHelper::cursorLineCol($buf, $offset);
 * ```
 */
final class TuiInputBufferHelper
{
    /**
     * @return int Количество символов в строке
     */
    public static function length(string $s): int
    {
        return mb_strlen($s);
    }

    /**
     * Нормализует offset в допустимые границы.
     */
    public static function clampOffset(string $buffer, int $offset): int
    {
        return max(0, min($offset, self::length($buffer)));
    }

    /**
     * Вставляет текст в буфер по offset.
     *
     * @return array{0:string,1:int} Новый буфер и новый offset
     */
    public static function insert(string $buffer, int $offset, string $text): array
    {
        $offset = self::clampOffset($buffer, $offset);
        if ($text === '') {
            return [$buffer, $offset];
        }

        $before = mb_substr($buffer, 0, $offset);
        $after = mb_substr($buffer, $offset);
        $buffer = $before . $text . $after;
        $offset += mb_strlen($text);
        return [$buffer, $offset];
    }

    /**
     * Удаляет символ слева от курсора.
     *
     * @return array{0:string,1:int}
     */
    public static function backspace(string $buffer, int $offset): array
    {
        $offset = self::clampOffset($buffer, $offset);
        if ($offset <= 0) {
            return [$buffer, $offset];
        }

        $before = mb_substr($buffer, 0, $offset - 1);
        $after = mb_substr($buffer, $offset);
        return [$before . $after, $offset - 1];
    }

    /**
     * Удаляет символ под курсором (Delete).
     *
     * @return array{0:string,1:int}
     */
    public static function delete(string $buffer, int $offset): array
    {
        $offset = self::clampOffset($buffer, $offset);
        $len = self::length($buffer);
        if ($offset >= $len) {
            return [$buffer, $offset];
        }

        $before = mb_substr($buffer, 0, $offset);
        $after = mb_substr($buffer, $offset + 1);
        return [$before . $after, $offset];
    }

    /**
     * @return list<string>
     */
    public static function splitLines(string $buffer): array
    {
        $lines = explode("\n", $buffer);
        if ($lines === []) {
            return [''];
        }
        return $lines;
    }

    /**
     * Возвращает позицию курсора в (line, col) относительно всего буфера.
     *
     * @return array{0:int,1:int} 0-based line, 0-based col
     */
    public static function cursorLineCol(string $buffer, int $offset): array
    {
        $offset = self::clampOffset($buffer, $offset);
        $before = mb_substr($buffer, 0, $offset);
        $parts = explode("\n", $before);
        $line = max(0, count($parts) - 1);
        $col = mb_strlen((string) end($parts));
        return [$line, $col];
    }

    /**
     * Возвращает offset по (line, col). col будет ограничен длиной строки.
     */
    public static function offsetFromLineCol(string $buffer, int $line, int $col): int
    {
        $lines = self::splitLines($buffer);
        $line = max(0, min($line, count($lines) - 1));

        $offset = 0;
        for ($i = 0; $i < $line; $i++) {
            $offset += mb_strlen((string) $lines[$i]) + 1; // +\n
        }

        $col = max(0, min($col, mb_strlen((string) $lines[$line])));
        return $offset + $col;
    }

    /**
     * Сдвигает курсор вверх/вниз на линию, пытаясь сохранить колонку.
     *
     * @param int $deltaLine -1 или +1
     * @return int новый offset
     */
    public static function moveVertically(string $buffer, int $offset, int $deltaLine): int
    {
        [$line, $col] = self::cursorLineCol($buffer, $offset);
        $lines = self::splitLines($buffer);
        $target = $line + $deltaLine;
        $target = max(0, min($target, count($lines) - 1));
        return self::offsetFromLineCol($buffer, $target, $col);
    }

    /**
     * Перемещает курсор в начало текущей строки.
     */
    public static function home(string $buffer, int $offset): int
    {
        [$line] = self::cursorLineCol($buffer, $offset);
        return self::offsetFromLineCol($buffer, $line, 0);
    }

    /**
     * Перемещает курсор в конец текущей строки.
     */
    public static function end(string $buffer, int $offset): int
    {
        [$line] = self::cursorLineCol($buffer, $offset);
        $lines = self::splitLines($buffer);
        $line = max(0, min($line, count($lines) - 1));
        $col = mb_strlen((string) $lines[$line]);
        return self::offsetFromLineCol($buffer, $line, $col);
    }

    /**
     * Возвращает 3 видимых строки поля ввода (viewport), начиная с topLine.
     *
     * @return array{0:list<string>,1:int} [lines(3), newTopLine]
     */
    public static function computeViewport(string $buffer, int $cursorOffset, int $topLine, int $height = 3): array
    {
        [$cursorLine] = self::cursorLineCol($buffer, $cursorOffset);
        $lines = self::splitLines($buffer);
        $maxTop = max(0, count($lines) - $height);

        $topLine = max(0, min($topLine, $maxTop));
        if ($cursorLine < $topLine) {
            $topLine = $cursorLine;
        } elseif ($cursorLine >= $topLine + $height) {
            $topLine = max(0, $cursorLine - ($height - 1));
        }
        $topLine = max(0, min($topLine, $maxTop));

        $out = [];
        for ($i = 0; $i < $height; $i++) {
            $out[] = (string) ($lines[$topLine + $i] ?? '');
        }

        return [$out, $topLine];
    }
}
