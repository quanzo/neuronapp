<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Вспомогательные методы для работы с комментариями в тексте.
 *
 * Позволяет удалять однострочные и многострочные PHP-комментарии
 * из произвольных текстовых блоков.
 */
class CommentsHelper
{
    /**
     * Удаляет PHP-комментарии из переданного текста.
     *
     * Поддерживаются:
     *  - однострочные комментарии, начинающиеся с двойного слэша до конца строки;
     *  - многострочные комментарии формата слэш-звёздочка ... звёздочка-слэш.
     *
     * @param string $text Входной текст, из которого необходимо убрать комментарии.
     *
     * @return string Текст без комментариев.
     */
    public static function stripComments(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Удаляем многострочные комментарии вида /* ... */
        $withoutBlock = preg_replace('#/\*.*?\*/#s', '', $text);
        if ($withoutBlock === null) {
            $withoutBlock = $text;
        }

        // Удаляем однострочные комментарии вида // ...
        $withoutLine = preg_replace('#//.*$#m', '', $withoutBlock);
        if ($withoutLine === null) {
            $withoutLine = $withoutBlock;
        }

        // Убираем лишние пробелы в конце строк и хвостовые переводы строк.
        $cleaned = preg_replace('/[ \t]+$/m', '', $withoutLine);
        if ($cleaned === null) {
            $cleaned = $withoutLine;
        }

        return rtrim($cleaned, "\n");
    }
}

