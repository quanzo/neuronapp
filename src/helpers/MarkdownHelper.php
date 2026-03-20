<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\tools\MarkdownChunkDto;
use app\modules\neuron\classes\dto\tools\MarkdownChunksResultDto;
use InvalidArgumentException;

use function array_values;
use function count;
use function implode;
use function explode;
use function in_array;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function preg_split;
use function rtrim;
use function strlen;
use function trim;

/**
 * Вспомогательный класс для markdown
 *
 * Пример использования:
 * `MarkdownHelper::safeMarkdownWhitespace($markdown);`
 */
class MarkdownHelper
{
    /**
     * Безопасно очищает Markdown от лишних пробелов, сохраняя форматирование.
     *
     * @param string $markdown Исходный текст Markdown.
     * @return string Обработанный текст.
     */
    public static function safeMarkdownWhitespace(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $inFenced = false;          // флаг: внутри fenced-блока кода
        $result = [];

        foreach ($lines as $line) {
            // Определяем начало/конец fenced-блока (``` или ~~~)
            if (preg_match('/^(?P<fence>`{3,}|~{3,})\s*$/', $line, $matches)) {
                $result[] = $line;          // fence оставляем без изменений
                $inFenced = !$inFenced;
                continue;
            }

            // Внутри fenced-блока строки не трогаем
            if ($inFenced) {
                $result[] = $line;
                continue;
            }

            // --- Обработка обычного текста (не код) ---
            // Жёсткий перенос markdown должен сохраняться только при ровно двух
            // завершающих пробелах, а не при 3+.
            $trailingSpacesCount = strlen($line) - strlen(rtrim($line, ' '));
            $hasTrailingDoubleSpace = ($trailingSpacesCount === 2);

            // Убираем конечные пробелы для последующей обработки (ведущие остаются!)
            $trimmed = rtrim($line);

            // Заменяем 3 и более пробелов подряд внутри строки на один пробел
            $processed = preg_replace('/ {3,}/', ' ', $trimmed);

            // Восстанавливаем два пробела в конце, если они были
            if ($hasTrailingDoubleSpace) {
                $processed .= '  ';
            }

            $result[] = $processed;
        }

        return implode("\n", $result);
    }

    /**
     * Семантически разбивает markdown-текст на чанки по целевому размеру.
     *
     * Сохраняет целостность таблиц, fenced-кода, абзацев и предложений.
     * Допускает недобор/перебор размера ради читаемости.
     *
     * Пример использования:
     * `MarkdownHelper::chunkBySemanticBlocks($markdown, 1200);`
     *
     * @param string $markdown    Исходный markdown-текст.
     * @param int    $targetChars Целевой размер чанка в символах.
     *
     * @return MarkdownChunksResultDto Результат разбиения с метаданными.
     */
    public static function chunkBySemanticBlocks(string $markdown, int $targetChars): MarkdownChunksResultDto
    {
        if ($targetChars <= 0) {
            throw new InvalidArgumentException('Параметр targetChars должен быть больше 0.');
        }

        $blocks = self::tokenizeMarkdownBlocks($markdown);
        if ($blocks === []) {
            return new MarkdownChunksResultDto($targetChars, []);
        }

        $normalizedBlocks = self::splitLongTextBlocksBySentences($blocks, $targetChars);
        return self::buildChunkResult($normalizedBlocks, $targetChars);
    }

    /**
     * Разбивает markdown на атомарные блоки для дальнейшей агрегации в чанки.
     *
     * @param string $markdown Исходный markdown-текст.
     *
     * @return array<int, array{kind: string, text: string}>
     */
    private static function tokenizeMarkdownBlocks(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        $lineCount = count($lines);
        $index = 0;
        $blocks = [];

        while ($index < $lineCount) {
            $line = $lines[$index];

            if (trim($line) === '') {
                $index++;
                continue;
            }

            if (self::isFenceLine($line)) {
                $fencedLines = [$line];
                $index++;

                while ($index < $lineCount) {
                    $fencedLines[] = $lines[$index];
                    if (self::isFenceLine($lines[$index])) {
                        $index++;
                        break;
                    }
                    $index++;
                }

                $blocks[] = ['kind' => 'code_fence', 'text' => implode("\n", $fencedLines)];
                continue;
            }

            if (self::isPotentialTableStart($lines, $index)) {
                $tableLines = [$lines[$index], $lines[$index + 1]];
                $index += 2;

                while ($index < $lineCount && self::isTableRow($lines[$index])) {
                    $tableLines[] = $lines[$index];
                    $index++;
                }

                $blocks[] = ['kind' => 'table', 'text' => implode("\n", $tableLines)];
                continue;
            }

            if (self::isHeadingLine($line)) {
                $blocks[] = ['kind' => 'heading', 'text' => $line];
                $index++;
                continue;
            }

            if (self::isListLine($line)) {
                $listLines = [$line];
                $index++;
                while ($index < $lineCount && trim($lines[$index]) !== '') {
                    if (!self::isListLine($lines[$index]) && !self::isListContinuationLine($lines[$index])) {
                        break;
                    }
                    $listLines[] = $lines[$index];
                    $index++;
                }

                $blocks[] = ['kind' => 'list', 'text' => implode("\n", $listLines)];
                continue;
            }

            $paragraphLines = [$line];
            $index++;
            while ($index < $lineCount && trim($lines[$index]) !== '') {
                if (
                    self::isFenceLine($lines[$index])
                    || self::isHeadingLine($lines[$index])
                    || self::isPotentialTableStart($lines, $index)
                    || self::isListLine($lines[$index])
                ) {
                    break;
                }

                $paragraphLines[] = $lines[$index];
                $index++;
            }

            $blocks[] = ['kind' => 'paragraph', 'text' => implode("\n", $paragraphLines)];
        }

        return $blocks;
    }

    /**
     * Делит слишком длинные текстовые блоки на группы предложений.
     *
     * @param array<int, array{kind: string, text: string}> $blocks      Исходные блоки.
     * @param int                                           $targetChars Целевой размер чанка.
     *
     * @return array<int, array{kind: string, text: string}>
     */
    private static function splitLongTextBlocksBySentences(array $blocks, int $targetChars): array
    {
        $result = [];

        foreach ($blocks as $block) {
            if (!in_array($block['kind'], ['paragraph', 'list'], true)) {
                $result[] = $block;
                continue;
            }

            if (mb_strlen($block['text']) <= $targetChars) {
                $result[] = $block;
                continue;
            }

            $parts = self::splitTextIntoSentenceGroups($block['text'], $targetChars);
            foreach ($parts as $part) {
                $result[] = [
                    'kind' => $block['kind'],
                    'text' => $part,
                ];
            }
        }

        return $result;
    }

    /**
     * Собирает итоговые чанки из атомарных блоков жадным алгоритмом.
     *
     * @param array<int, array{kind: string, text: string}> $blocks      Список блоков.
     * @param int                                           $targetChars Целевой размер чанка.
     *
     * @return MarkdownChunksResultDto
     */
    private static function buildChunkResult(array $blocks, int $targetChars): MarkdownChunksResultDto
    {
        $chunks = [];
        $currentTexts = [];
        $currentKinds = [];
        $currentLength = 0;
        $chunkIndex = 0;

        foreach ($blocks as $block) {
            $blockText = $block['text'];
            $blockLength = mb_strlen($blockText);
            $separator = $currentTexts === [] ? '' : "\n\n";
            $projectedLength = $currentLength + mb_strlen($separator) + $blockLength;

            if ($currentTexts !== [] && $projectedLength > $targetChars) {
                $text = implode("\n\n", $currentTexts);
                $chunks[] = new MarkdownChunkDto(
                    index: $chunkIndex,
                    text: $text,
                    lengthChars: mb_strlen($text),
                    blockKinds: array_values($currentKinds),
                    isOversized: mb_strlen($text) > $targetChars
                );
                $chunkIndex++;
                $currentTexts = [];
                $currentKinds = [];
                $currentLength = 0;
            }

            $currentTexts[] = $blockText;
            $currentKinds[$block['kind']] = $block['kind'];
            $currentLength = mb_strlen(implode("\n\n", $currentTexts));
        }

        if ($currentTexts !== []) {
            $text = implode("\n\n", $currentTexts);
            $chunks[] = new MarkdownChunkDto(
                index: $chunkIndex,
                text: $text,
                lengthChars: mb_strlen($text),
                blockKinds: array_values($currentKinds),
                isOversized: mb_strlen($text) > $targetChars
            );
        }

        return new MarkdownChunksResultDto($targetChars, $chunks);
    }

    /**
     * Делит текст на группы предложений, не разрывая слова.
     *
     * @param string $text        Текст для деления.
     * @param int    $targetChars Целевой размер группы.
     *
     * @return string[] Массив текстовых частей.
     */
    private static function splitTextIntoSentenceGroups(string $text, int $targetChars): array
    {
        $sentences = preg_split('/(?<=[.!?…])\s+/u', trim($text)) ?: [];
        if ($sentences === []) {
            return [$text];
        }

        $groups = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $candidate = $current === '' ? $sentence : $current . ' ' . $sentence;
            if ($current !== '' && mb_strlen($candidate) > $targetChars) {
                $groups[] = $current;
                $current = $sentence;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $groups[] = $current;
        }

        return $groups === [] ? [$text] : $groups;
    }

    /**
     * Проверяет, является ли строка маркером fenced-кода.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isFenceLine(string $line): bool
    {
        return (bool) preg_match('/^\s*(`{3,}|~{3,})/', $line);
    }

    /**
     * Проверяет, является ли строка markdown-заголовком.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isHeadingLine(string $line): bool
    {
        return (bool) preg_match('/^\s{0,3}#{1,6}\s+\S/u', $line);
    }

    /**
     * Проверяет, является ли строка элементом списка.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isListLine(string $line): bool
    {
        return (bool) preg_match('/^\s*(?:[-*+]\s+|\d+\.\s+)/u', $line);
    }

    /**
     * Проверяет, является ли строка продолжением элемента списка.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isListContinuationLine(string $line): bool
    {
        return (bool) preg_match('/^\s{2,}\S/u', $line);
    }

    /**
     * Проверяет, может ли текущая строка быть началом таблицы.
     *
     * @param string[] $lines Список строк markdown.
     * @param int      $index Индекс текущей строки.
     *
     * @return bool
     */
    private static function isPotentialTableStart(array $lines, int $index): bool
    {
        if (!isset($lines[$index + 1])) {
            return false;
        }

        return self::isTableRow($lines[$index]) && self::isTableDelimiterRow($lines[$index + 1]);
    }

    /**
     * Проверяет, похожа ли строка на строку таблицы markdown.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isTableRow(string $line): bool
    {
        return (bool) preg_match('/\|/', $line);
    }

    /**
     * Проверяет, является ли строка разделителем заголовка таблицы markdown.
     *
     * @param string $line Строка markdown.
     *
     * @return bool
     */
    private static function isTableDelimiterRow(string $line): bool
    {
        return (bool) preg_match('/^\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*$/', $line);
    }
}
