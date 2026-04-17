<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\tui\render;

use app\modules\neuron\classes\dto\tui\history\TuiHistoryDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryEntryDto;
use app\modules\neuron\classes\dto\tui\view\TuiThemeDto;
use app\modules\neuron\classes\dto\tui\view\TuiBlockOptionsDto;
use app\modules\neuron\classes\dto\tui\view\blocks\CodeBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\DividerBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\HeadingBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\KeyHintsBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\ListBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\NoticeBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\PanelBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\TableBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\TextBlockDto;
use app\modules\neuron\enums\tui\TuiNoticeKindEnum;
use app\modules\neuron\helpers\TuiTextHelper;
use app\modules\neuron\interfaces\tui\view\TuiBlockInterface;
use app\modules\neuron\interfaces\tui\view\TuiHistoryFormatterInterface;

/**
 * Форматтер истории TUI: entries/blocks → плоские строки.
 *
 * Обязанности:
 * - корректный расчёт ширины (учёт Unicode, игнор ANSI);
 * - перенос строк (wrap) там, где допустимо;
 * - базовая типографика (таблицы, панели, блоки кода).
 *
 * Важное свойство реализации:
 * - форматирование не должно мутировать входные DTO/блоки (они часто лежат в истории и переиспользуются);
 * - дополнительные отступы для вложенных блоков применяются временно, без накопления между рендерами.
 *
 * Пример использования:
 *
 * ```php
 * $formatter = new TuiHistoryFormatter();
 * $lines = $formatter->toDisplayLines($history, $innerWidth, new TuiThemeDto());
 * ```
 */
final class TuiHistoryFormatter implements TuiHistoryFormatterInterface
{
    /**
     * {@inheritDoc}
     *
     * Преобразует все entries истории в линейный список строк для вывода в области output.
     * Между entries добавляет пустую строку-разделитель.
     *
     * @param TuiHistoryDto $history История TUI (entries + blocks)
     * @param int $innerWidth Доступная ширина контента (в колонках), без учёта внешних рамок
     * @param TuiThemeDto $theme Тема (ANSI-цвета) для стилизации блоков
     * @return list<string> Строки для отображения (без финального пустого разделителя)
     */
    public function toDisplayLines(TuiHistoryDto $history, int $innerWidth, TuiThemeDto $theme): array
    {
        $innerWidth = max(0, $innerWidth);
        $lines = [];

        foreach ($history->getEntries() as $entry) {
            $entryLines = $this->formatEntry($entry, $innerWidth, $theme);
            foreach ($entryLines as $l) {
                $lines[] = $l;
            }
            $lines[] = '';
        }

        if (!empty($lines) && end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    /**
     * Форматирует одну запись истории.
     *
     * Если entry не содержит blocks, но содержит plainText — он будет представлен как `TextBlockDto`.
     *
     * @return list<string>
     */
    private function formatEntry(TuiHistoryEntryDto $entry, int $innerWidth, TuiThemeDto $theme): array
    {
        $blocks = $entry->getBlocks();
        if (empty($blocks)) {
            $plain = $entry->getPlainText();
            if ($plain === null || $plain === '') {
                return [];
            }
            $blocks = [new TextBlockDto($plain)];
        }

        $out = [];
        foreach ($blocks as $block) {
            foreach ($this->formatBlock($block, $innerWidth, $theme) as $l) {
                $out[] = $l;
            }
        }
        return $out;
    }

    /**
     * Делегирует форматирование конкретного блока по его `type`.
     *
     * @param TuiBlockInterface $block
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatBlock(TuiBlockInterface $block, int $innerWidth, TuiThemeDto $theme): array
    {
        return match ($block->getType()) {
            HeadingBlockDto::TYPE => $this->formatHeading($block, $innerWidth, $theme),
            TextBlockDto::TYPE => $this->formatText($block, $innerWidth, $theme),
            NoticeBlockDto::TYPE => $this->formatNotice($block, $innerWidth, $theme),
            DividerBlockDto::TYPE => $this->formatDivider($block, $innerWidth, $theme),
            ListBlockDto::TYPE => $this->formatList($block, $innerWidth, $theme),
            TableBlockDto::TYPE => $this->formatTable($block, $innerWidth, $theme),
            CodeBlockDto::TYPE => $this->formatCode($block, $innerWidth, $theme),
            PanelBlockDto::TYPE => $this->formatPanel($block, $innerWidth, $theme),
            KeyHintsBlockDto::TYPE => $this->formatKeyHints($block, $innerWidth, $theme),
            default => $this->formatText(new TextBlockDto('[unsupported block: ' . $block->getType() . ']'), $innerWidth, $theme),
        };
    }

    /**
     * Форматирует заголовок (heading), опционально с underline.
     *
     * @param HeadingBlockDto $b
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatHeading(TuiBlockInterface $b, int $innerWidth, TuiThemeDto $theme): array
    {
        /** @var HeadingBlockDto $b */
        $indent = $b->getOptions()->getIndent();
        $w = max(0, $innerWidth - $indent);
        $title = TuiTextHelper::trimToWidthWithEllipsis($b->getText(), $w);
        $line = str_repeat(' ', $indent) . $theme->accent() . $title . $theme->reset();

        if (!$b->hasUnderline()) {
            return [$line];
        }

        $underline = str_repeat(' ', $indent) . $theme->muted() . str_repeat('─', max(0, min($w, max(1, TuiTextHelper::visibleWidth($title))))) . $theme->reset();
        return [$line, $underline];
    }

    /**
     * Форматирует текстовый блок.
     *
     * Поведение зависит от `options.wrap`:
     * - wrap=true: текст переносится по ширине `innerWidth - indent`;
     * - wrap=false: строка обрезается с многоточием.
     *
     * @param TextBlockDto $b
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatText(TuiBlockInterface $b, int $innerWidth, TuiThemeDto $theme): array
    {
        /** @var TextBlockDto $b */
        $indent = $b->getOptions()->getIndent();
        $wrap = $b->getOptions()->isWrap();
        $w = max(0, $innerWidth - $indent);
        $prefix = str_repeat(' ', $indent);

        $rawLines = explode("\n", $b->getText());
        $out = [];
        foreach ($rawLines as $raw) {
            if ($wrap) {
                foreach (TuiTextHelper::splitByWidth((string) $raw, $w) as $chunk) {
                    $out[] = $prefix . $chunk;
                }
            } else {
                $out[] = $prefix . TuiTextHelper::trimToWidthWithEllipsis((string) $raw, $w);
            }
        }
        return $out;
    }

    /**
     * Форматирует notice-блок (`[OK]`, `[WARN]`, ...), перенося текст с учётом ширины префикса.
     *
     * @param NoticeBlockDto $b
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatNotice(TuiBlockInterface $b, int $innerWidth, TuiThemeDto $theme): array
    {
        /** @var NoticeBlockDto $b */
        $indent = $b->getOptions()->getIndent();
        $w = max(0, $innerWidth - $indent);
        $prefix = str_repeat(' ', $indent);

        $label = match ($b->getKind()) {
            TuiNoticeKindEnum::Success => 'OK',
            TuiNoticeKindEnum::Warning => 'WARN',
            TuiNoticeKindEnum::Error => 'ERR',
            default => 'INFO',
        };

        $color = match ($b->getKind()) {
            TuiNoticeKindEnum::Success => $theme->success(),
            TuiNoticeKindEnum::Warning => $theme->warning(),
            TuiNoticeKindEnum::Error => $theme->error(),
            default => $theme->muted(),
        };

        $text = $b->getText();
        $head = $prefix . $color . '[' . $label . ']' . $theme->reset() . ' ';
        $headW = TuiTextHelper::visibleWidth($head);
        $available = max(0, $w - $headW);

        $chunks = TuiTextHelper::splitByWidth($text, $available);
        if ($chunks === []) {
            return [$head];
        }

        $out = [];
        foreach ($chunks as $i => $chunk) {
            if ($i === 0) {
                $out[] = $head . $chunk;
                continue;
            }
            $out[] = $prefix . str_repeat(' ', $headW) . $chunk;
        }
        return $out;
    }

    /**
     * Форматирует divider (повтор символа `char` на всю доступную ширину).
     *
     * @param DividerBlockDto $b
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatDivider(TuiBlockInterface $b, int $innerWidth, TuiThemeDto $theme): array
    {
        /** @var DividerBlockDto $b */
        $indent = $b->getOptions()->getIndent();
        $w = max(0, $innerWidth - $indent);
        $prefix = str_repeat(' ', $indent);
        return [$prefix . $theme->muted() . str_repeat($b->getChar(), $w) . $theme->reset()];
    }

    /**
     * Форматирует список.
     *
     * Алгоритм:
     * - вычисляем ширину bullet (в колонках) + пробел;
     * - переносим элементы так, чтобы продолжение строки выравнивалось под текстом, а не под bullet.
     *
     * @param ListBlockDto $b
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatList(TuiBlockInterface $b, int $innerWidth, TuiThemeDto $theme): array
    {
        /** @var ListBlockDto $b */
        $indent = $b->getOptions()->getIndent();
        $w = max(0, $innerWidth - $indent);
        $prefix = str_repeat(' ', $indent);

        $bullet = $b->getBullet();
        $bulletPrefix = $bullet . ' ';
        $bulletW = mb_strwidth($bulletPrefix);
        $itemW = max(0, $w - $bulletW);

        $out = [];
        foreach ($b->getItems() as $item) {
            $chunks = TuiTextHelper::splitByWidth((string) $item, $itemW);
            foreach ($chunks as $i => $chunk) {
                if ($i === 0) {
                    $out[] = $prefix . $bulletPrefix . $chunk;
                    continue;
                }
                $out[] = $prefix . str_repeat(' ', $bulletW) . $chunk;
            }
        }
        return $out;
    }

    /**
     * Форматирует таблицу (headers + rows) в моноширинное представление.
     *
     * Особенности:
     * - ширины колонок вычисляются по максимальной «видимой» ширине (ANSI игнорируется);
     * - если таблица не помещается, «сжимаем» последнюю колонку, но оставляем минимум для читабельности.
     *
     * @param TableBlockDto $b
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatTable(TuiBlockInterface $b, int $innerWidth, TuiThemeDto $theme): array
    {
        /** @var TableBlockDto $b */
        $indent = $b->getOptions()->getIndent();
        $prefix = str_repeat(' ', $indent);
        $w = max(0, $innerWidth - $indent);

        $headers = $b->getHeaders();
        $rows = $b->getRows();
        $colCount = max(count($headers), ...array_map('count', $rows ?: [[]]));
        if ($colCount <= 0) {
            return [];
        }

        $cols = [];
        for ($c = 0; $c < $colCount; $c++) {
            $cols[$c] = 1;
        }

        for ($c = 0; $c < $colCount; $c++) {
            $h = (string) ($headers[$c] ?? '');
            $cols[$c] = max($cols[$c], TuiTextHelper::visibleWidth($h));
        }
        foreach ($rows as $r) {
            for ($c = 0; $c < $colCount; $c++) {
                $cell = (string) ($r[$c] ?? '');
                $cols[$c] = max($cols[$c], TuiTextHelper::visibleWidth($cell));
            }
        }

        // Коррекция под доступную ширину (сжимаем последнюю колонку).
        $sepW = 3; // " | "
        $needed = array_sum($cols) + ($colCount - 1) * $sepW;
        if ($needed > $w && $colCount > 0) {
            $over = $needed - $w;
            $last = $colCount - 1;
            $cols[$last] = max(4, $cols[$last] - $over);
        }

        $out = [];

        if ($b->isShowHeader()) {
            $out[] = $prefix . $this->formatTableRow($headers, $cols, $sepW);
            $out[] = $prefix . $theme->muted() . $this->formatTableSeparator($cols, $sepW) . $theme->reset();
        }

        foreach ($rows as $r) {
            $out[] = $prefix . $this->formatTableRow($r, $cols, $sepW);
        }

        return $out;
    }

    /**
     * Форматирует строку таблицы: выравнивание по колонкам и обрезка с многоточием.
     *
     * @param list<string> $cells
     * @param list<int> $widths
     * @param int $sepW Ширина сепаратора между колонками (в текущей реализации всегда 3: " | ")
     * @return string Одна строка таблицы без ANSI, с пробельным выравниванием
     */
    private function formatTableRow(array $cells, array $widths, int $sepW): string
    {
        $parts = [];
        foreach ($widths as $i => $w) {
            $cell = (string) ($cells[$i] ?? '');
            $cell = TuiTextHelper::trimToWidthWithEllipsis($cell, $w);
            $parts[] = TuiTextHelper::padString($cell, $w);
        }
        return implode(' | ', $parts);
    }

    /**
     * Формирует разделитель таблицы под заголовком.
     *
     * @param list<int> $widths
     * @param int $sepW
     * @return string
     */
    private function formatTableSeparator(array $widths, int $sepW): string
    {
        $parts = [];
        foreach ($widths as $w) {
            $parts[] = str_repeat('-', $w);
        }
        return implode(str_repeat('-', $sepW), $parts);
    }

    /**
     * Форматирует блок кода.
     *
     * Особенности:
     * - может отображать язык (например, `[php]`) отдельной строкой;
     * - опционально отображает номера строк, учитывая их ширину;
     * - каждая строка кода обрезается до доступной ширины.
     *
     * @param CodeBlockDto $b
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatCode(TuiBlockInterface $b, int $innerWidth, TuiThemeDto $theme): array
    {
        /** @var CodeBlockDto $b */
        $indent = $b->getOptions()->getIndent();
        $prefix = str_repeat(' ', $indent);
        $w = max(0, $innerWidth - $indent);

        $out = [];
        if ($b->getLanguage() !== null) {
            $out[] = $prefix . $theme->muted() . '[' . $b->getLanguage() . ']' . $theme->reset();
        }

        $lines = preg_split("/\\r\\n|\\n|\\r/", $b->getCode()) ?: [];
        $lnPad = $b->hasLineNumbers() ? strlen((string) max(1, count($lines))) : 0;
        foreach ($lines as $i => $line) {
            $ln = $i + 1;
            $head = '';
            if ($b->hasLineNumbers()) {
                $head = $theme->muted() . str_pad((string) $ln, $lnPad, ' ', STR_PAD_LEFT) . $theme->reset() . ' ';
            }
            $contentW = max(0, $w - TuiTextHelper::visibleWidth($head));
            $trimmed = TuiTextHelper::trimToWidthWithEllipsis((string) $line, $contentW);
            $out[] = $prefix . $head . $theme->code() . $trimmed . $theme->reset();
        }
        return $out;
    }

    /**
     * Форматирует панель: заголовок + underline + вложенные блоки с увеличенным отступом.
     *
     * @param PanelBlockDto $b
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatPanel(TuiBlockInterface $b, int $innerWidth, TuiThemeDto $theme): array
    {
        /** @var PanelBlockDto $b */
        $indent = $b->getOptions()->getIndent();
        $prefix = str_repeat(' ', $indent);
        $w = max(0, $innerWidth - $indent);

        $out = [];
        $title = TuiTextHelper::trimToWidthWithEllipsis($b->getTitle(), $w);
        $out[] = $prefix . $theme->accent() . $title . $theme->reset();
        $out[] = $prefix . $theme->muted() . str_repeat('─', max(0, min($w, max(1, TuiTextHelper::visibleWidth($title))))) . $theme->reset();

        foreach ($b->getBody() as $child) {
            // Вставляем вложенные блоки с дополнительным отступом, но без мутации истории.
            // Иначе каждый последующий рендер увеличивает indent и контент «уплывает» вправо.
            $childLines = $this->formatBlockWithExtraIndent($child, 2, $innerWidth, $theme);
            foreach ($childLines as $l) {
                $out[] = $l;
            }
        }

        return $out;
    }

    /**
     * Форматирует блок с временным дополнительным отступом, без побочных эффектов на DTO.
     *
     * Почему это важно:
     * - блоки (и их options) могут храниться в истории;
     * - мутация `indent` приводит к накоплению отступа между перерисовками и визуальному «съезду вправо».
     *
     * Реализация:
     * - создаём новый `TuiBlockOptionsDto`, копируя флаги `wrap/compact`;
     * - временно присваиваем его блоку;
     * - после форматирования возвращаем оригинальные options в `finally`.
     *
     * @param TuiBlockInterface $b
     * @param int $extraIndent Сколько пробелов добавить к текущему `indent`
     * @param int $innerWidth
     * @param TuiThemeDto $theme
     * @return list<string>
     */
    private function formatBlockWithExtraIndent(TuiBlockInterface $b, int $extraIndent, int $innerWidth, TuiThemeDto $theme): array
    {
        if (!method_exists($b, 'getOptions') || !method_exists($b, 'setOptions')) {
            return $this->formatBlock($b, $innerWidth, $theme);
        }

        /** @var mixed $origOpt */
        $origOpt = $b->getOptions();
        if (!is_object($origOpt) || !method_exists($origOpt, 'getIndent')) {
            return $this->formatBlock($b, $innerWidth, $theme);
        }

        $oldIndent = (int) $origOpt->getIndent();

        $newOpt = new TuiBlockOptionsDto();
        $newOpt->setIndent($oldIndent + $extraIndent);
        if (method_exists($origOpt, 'isWrap')) {
            /** @var mixed $wrap */
            $wrap = $origOpt->isWrap();
            $newOpt->setWrap((bool) $wrap);
        }
        if (method_exists($origOpt, 'isCompact')) {
            /** @var mixed $compact */
            $compact = $origOpt->isCompact();
            $newOpt->setCompact((bool) $compact);
        }

        $b->setOptions($newOpt);
        try {
            return $this->formatBlock($b, $innerWidth, $theme);
        } finally {
            $b->setOptions($origOpt);
        }
    }

    /**
     * @param KeyHintsBlockDto $b
     * @return list<string>
     */
    private function formatKeyHints(TuiBlockInterface $b, int $innerWidth, TuiThemeDto $theme): array
    {
        /** @var KeyHintsBlockDto $b */
        $indent = $b->getOptions()->getIndent();
        $prefix = str_repeat(' ', $indent);
        $w = max(0, $innerWidth - $indent);
        $joined = implode($b->getSeparator(), $b->getItems());
        $joined = TuiTextHelper::trimToWidthWithEllipsis($joined, $w);
        return [$prefix . $theme->muted() . $joined . $theme->reset()];
    }
}
