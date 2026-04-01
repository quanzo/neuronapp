<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\convert;

use DOMDocument;
use DOMNode;

use function html_entity_decode;
use function implode;
use function is_string;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function mb_strlen;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function strip_tags;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * HTML ⇄ Markdown (и markdown-like текст).
 *
 * Это легковесный конвертер, ориентированный на **стабильный** «LLM-friendly» результат.
 *
 * Ключевые цели:
 *
 * - **Стабильность**: на реальном web‑HTML (с шумом, вложенностями и "кривой" разметкой) результат
 *   должен быть предсказуемым и не разваливаться в пустоту.
 * - **Чистота текста**: не допускать попадания JS/CSS в выходной текст (типичный баг конвертеров,
 *   которые в конце делают `strip_tags()` — текст внутри `<script>` остаётся).
 * - **Смысл важнее идеала**: это не полноценный "идеальный" HTML→Markdown движок; задача — выдать
 *   удобочитаемый markdown-like текст для дальнейшей обработки LLM/поиском.
 *
 * Пайплайн `htmlToMarkdown()`:
 *
 * 1) **DOM‑санитизация**: удаляем шумные/опасные элементы (`script`, `style`, `noscript`, `iframe`, …),
 *    а также HTML‑комментарии.
 * 2) **Защита `pre/code`**: блоки `<pre>` заменяем плейсхолдерами, чтобы regex‑конвертация и `strip_tags()`
 *    не разрушили форматирование. Потом восстанавливаем как fenced‑code.
 * 3) **Блочные элементы**: отдельно обрабатываем `<hr>` и `<blockquote>` (до `strip_tags()`).
 * 4) **Структурные элементы**: картинки, ссылки, таблицы, списки — отдельными обработчиками.
 * 5) **Базовые теги**: заголовки/абзацы/выделения — простыми правилами замены.
 * 6) **Финализация**: `strip_tags()`, восстановление плейсхолдеров, декод сущностей, нормализация пустых строк.
 *
 * @see https://github.com/shengkung/mdify
 *
 * Пример:
 *
 * <code>
 * $markdown = Mdify::htmlToMarkdown('<h1>Hello</h1><p>World</p>');
 * </code>
 */
class Mdify
{
    /**
     * HTML → Markdown главный метод конвертации.
     *
     * Важно: конвертация выполняется по принципу best‑effort, потому что реальный HTML часто содержит:
     *
     * - скрипты, стили, скрытые элементы, навигацию, мусорные контейнеры;
     * - вложенные списки/таблицы со сложными атрибутами и нестандартной вложенностью;
     * - незакрытые теги или HTML‑фрагменты вместо полноценного документа.
     *
     * Поэтому метод сначала делает DOM‑очистку и защиту pre‑блоков, затем применяет набор
     * преобразований, ориентированных на практичный markdown‑like результат.
     *
     * @param string $sHtmlContent HTML контент (допускается фрагмент HTML)
     *
     * @return string Markdown/markdown-like текст
     */
    public static function htmlToMarkdown(string $sHtmlContent): string
    {
        $sHtmlContent = trim($sHtmlContent);
        if ($sHtmlContent === '') {
            return '';
        }

        // 1) DOM‑очистка.
        //
        // Наивный подход "сначала заменим теги, потом strip_tags()" опасен тем, что JS/CSS
        // из <script>/<style> будет сохранён как обычный текст.
        // Поэтому сначала удаляем такие узлы из DOM и только потом делаем regex‑конвертацию.
        $sHtmlContent = self::sanitizeHtml($sHtmlContent);

        // 2) Защита preformatted блоков.
        //
        // Блоки <pre> обычно содержат код/логи/конфиги, для которых критичны переносы строк.
        // Мы заменяем каждый <pre> плейсхолдером, а содержимое сохраняем как fenced‑code.
        $protected = self::protectPreformattedBlocks($sHtmlContent);
        $sHtmlContent = $protected['html'];
        $protectedBlocks = $protected['blocks'];

        // 3) Блочные элементы, которые проще и надёжнее обработать ДО strip_tags().
        //
        // Это помогает сохранить границы блоков (цитаты/разделители), не полагаясь на угадывание
        // после удаления тегов.
        $sHtmlContent = self::convertHtmlHorizontalRules($sHtmlContent);
        $sHtmlContent = self::convertHtmlBlockquotes($sHtmlContent);
        $sHtmlContent = self::convertInlineCodeTags($sHtmlContent);

        // 4) Убираем "пустые" абзацы, которые дают лишние пустые строки в markdown.
        $sPattern = '/<p>(\s|&nbsp;|<br\s*\/?>|<strong>(\s|&nbsp;)*<\/strong>|<span[^>]*>(\s|&nbsp;)*<\/span>)*<\/p>/i';
        $sMarkdown = preg_replace($sPattern, '', $sHtmlContent) ?? $sHtmlContent;

        // 5) Конвертируем элементы, которые не должны пропасть после strip_tags():
        // - изображения и ссылки: иначе <img>/<a> исчезнут без следа
        // - таблицы: переводим в pipe‑таблицы
        // - списки: пытаемся сохранить маркеры и вложенность
        $sMarkdown = self::convertHtmlImages($sMarkdown);
        $sMarkdown = self::convertHtmlLinks($sMarkdown);
        $sMarkdown = self::convertHtmlTables($sMarkdown);
        $sMarkdown = self::nestedLists($sMarkdown);

        // 6) Базовые правила замены для простых тегов (заголовки/абзацы/выделения/переносы).
        $aReplacePatterns = [
            '/<h1[^>]*>(.*?)<\/h1>/is' => "# $1\n\n",
            '/<h2[^>]*>(.*?)<\/h2>/is' => "## $1\n\n",
            '/<h3[^>]*>(.*?)<\/h3>/is' => "### $1\n\n",
            '/<h4[^>]*>(.*?)<\/h4>/is' => "#### $1\n\n",
            '/<h5[^>]*>(.*?)<\/h5>/is' => "##### $1\n\n",
            '/<h6[^>]*>(.*?)<\/h6>/is' => "###### $1\n\n",
            '/<strong[^>]*>(.*?)<\/strong>/is' => '**$1**',
            '/<b[^>]*>(.*?)<\/b>/is' => '**$1**',
            '/<em[^>]*>(.*?)<\/em>/is' => '*$1*',
            '/<i[^>]*>(.*?)<\/i>/is' => '*$1*',
            '/<br\s*\/?>/is' => "\n",
            '/<p[^>]*>(.*?)<\/p>/is' => "$1\n\n",
            // '/<img[^>]*src=[\"\'](.*?)[\"\'][^>]*alt=[\"\'](.*?)[\"\'][^>]*>/is' => '![$2]($1)', // Handled by convertHtmlImages
        ];

        // Применяем правила по очереди.
        foreach ($aReplacePatterns as $k1 => $v1) {
            $sMarkdown = preg_replace($k1, $v1, $sMarkdown) ?? $sMarkdown;
        }

        // 7) Удаляем оставшиеся HTML‑теги.
        $sMarkdown = strip_tags($sMarkdown);

        // 8) Восстанавливаем защищённые <pre> блоки (fenced‑code) по плейсхолдерам.
        if ($protectedBlocks !== []) {
            foreach ($protectedBlocks as $placeholder => $blockMarkdown) {
                $sMarkdown = str_replace($placeholder, $blockMarkdown, $sMarkdown);
            }
        }

        // 9) Декодируем HTML‑сущности (например, &amp;, &nbsp;) после strip_tags().
        $sMarkdown = html_entity_decode($sMarkdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 10) Нормализация пустых строк: оставляем максимум 2 подряд.
        $sMarkdown = preg_replace("/(\n\s*){3,}/", "\n\n", $sMarkdown) ?? $sMarkdown;

        // Финал: убираем пробелы/переводы строк по краям.
        return trim($sMarkdown);
    }

    /**
     * DOM-based HTML sanitization for stable conversion.
     *
     * Удаляет элементы, которые чаще всего:
     * - содержат исполняемый код/стили;
     * - не несут полезного текста для LLM;
     * - ломают regex-конвертацию.
     *
     * @param string $html Raw html
     *
     * @return string Sanitized html
     */
    private static function sanitizeHtml(string $html): string
    {
        $useErrors = libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        // Оборачиваем в минимальный документ: так DOMDocument устойчивее к фрагментам HTML.
        $loaded = $dom->loadHTML(
            '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>'
        );

        libxml_clear_errors();
        libxml_use_internal_errors($useErrors);

        if ($loaded !== true) {
            // Fallback: если DOM не загрузился, хотя бы вырежем скрипты/стили регексом.
            $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html) ?? $html;
            $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $html) ?? $html;
            $html = preg_replace('/<!--[\s\S]*?-->/i', '', $html) ?? $html;
            return $html;
        }

        $removeTags = [
            'script',
            'style',
            'noscript',
            'iframe',
            'canvas',
            'svg',
            'form',
            'button',
            'input',
            'select',
            'textarea',
            'link',
            'meta',
            'head',
        ];

        foreach ($removeTags as $tag) {
            /** @var \DOMNodeList $nodes */
            $nodes = $dom->getElementsByTagName($tag);
            // NodeList "live", поэтому удаляем с конца.
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // Удаляем HTML-комментарии.
        self::removeDomComments($dom);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $html;
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Рекурсивно удаляет comment‑узлы из DOM.
     *
     * Комментарии в HTML часто содержат мусор, служебные инструкции или куски шаблонов.
     * Если их не удалять, они могут попадать в результат после `strip_tags()` и ухудшать качество текста.
     *
     * @param DOMNode $node Узел, от которого начинается рекурсивный обход
     */
    private static function removeDomComments(DOMNode $node): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        // Идём с конца, чтобы безопасно удалять узлы во время обхода.
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if (!$child) {
                continue;
            }

            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
                continue;
            }

            self::removeDomComments($child);
        }
    }

    /**
     * Защищает `<pre>` (и `<pre><code>`) блоки, заменяя их плейсхолдерами.
     *
     * Почему это нужно:
     *
     * - `<pre>` обычно содержит код/логи/вывод команд, где важны переносы строк.
     * - Если пропустить `<pre>` через общие regex и `strip_tags()`, форматирование кода почти гарантированно сломается.
     *
     * Как работает:
     *
     * - каждый `<pre>...</pre>` заменяется на уникальный плейсхолдер `[[[MDIFY_PRE_BLOCK_N]]]`;
     * - содержимое очищается от внешних тегов `<pre>` и `<code>`, затем:
     *   - `strip_tags()` удаляет остаточные теги (например, подсветка кода);
     *   - `html_entity_decode()` возвращает символы из `&lt;`, `&gt;`, `&amp;` и т.д.;
     * - плейсхолдер сопоставляется с fenced code block формата:
     *
     * ```text
     * ...
     * ```
     *
     * Ограничение: язык code block фиксирован как `text`, потому что по HTML нельзя надёжно
     * определить язык, а "угадывание" часто приводит к ошибкам подсветки.
     *
     * @return array{html:string,blocks:array<string,string>}
     */
    private static function protectPreformattedBlocks(string $html): array
    {
        $blocks = [];
        $i = 0;

        $html = preg_replace_callback('/<pre\b[^>]*>[\s\S]*?<\/pre>/i', static function (array $m) use (&$blocks, &$i): string {
            $raw = $m[0];
            $inner = preg_replace('/^<pre\b[^>]*>/i', '', $raw) ?? $raw;
            $inner = preg_replace('/<\/pre>$/i', '', $inner) ?? $inner;

            // Убираем оболочку <code> (если есть), оставляя содержимое.
            $inner = preg_replace('/^<code\b[^>]*>/i', '', $inner) ?? $inner;
            $inner = preg_replace('/<\/code>$/i', '', $inner) ?? $inner;

            // Превращаем HTML внутри блока в "сырой" текст.
            $text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            $text = trim($text);

            $placeholder = "\n\n[[[MDIFY_PRE_BLOCK_" . $i . "]]]\n\n";
            $blocks[$placeholder] = "\n```text\n" . $text . "\n```\n";
            $i++;
            return $placeholder;
        }, $html) ?? $html;

        return [
            'html' => $html,
            'blocks' => $blocks,
        ];
    }

    /**
     * Конвертирует `<hr>` в markdown‑разделитель `---`.
     *
     * Делается до `strip_tags()`, чтобы не потерять семантику разделителя.
     *
     * @param string $html HTML фрагмент
     *
     * @return string HTML с заменёнными `<hr>`
     */
    private static function convertHtmlHorizontalRules(string $html): string
    {
        return preg_replace('/<hr\b[^>]*\/?>/i', "\n\n---\n\n", $html) ?? $html;
    }

    /**
     * Конвертирует `<blockquote>` в markdown‑цитаты (`> ...`).
     *
     * Делается до общего `strip_tags()`, чтобы не потерять границы цитаты.
     *
     * Реальный HTML внутри `<blockquote>` часто содержит `<p>` и `<br>`.
     * Если просто сделать `strip_tags()`, абзацы могут "склеиться" в одну строку.
     * Поэтому предварительно нормализуем разрывы:
     *
     * - `</p><p>` → `\n`
     * - `<br>` → `\n`
     *
     * @param string $html HTML фрагмент
     *
     * @return string HTML с заменёнными `<blockquote>`
     */
    private static function convertHtmlBlockquotes(string $html): string
    {
        return preg_replace_callback('/<blockquote\b[^>]*>([\s\S]*?)<\/blockquote>/i', static function (array $m): string {
            // Сохраняем разрывы между абзацами внутри цитаты до strip_tags().
            $innerHtml = $m[1];
            $innerHtml = preg_replace('/<\/p>\s*<p\b[^>]*>/i', "\n", $innerHtml) ?? $innerHtml;
            $innerHtml = preg_replace('/<br\s*\/?>/i', "\n", $innerHtml) ?? $innerHtml;

            $inner = trim(strip_tags($innerHtml));
            $inner = str_replace(["\r\n", "\r"], "\n", $inner);
            if ($inner === '') {
                return '';
            }
            $lines = preg_split('/\n+/', $inner) ?: [];
            $quoted = [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $quoted[] = '> ' . $line;
            }
            return "\n\n" . implode("\n", $quoted) . "\n\n";
        }, $html) ?? $html;
    }

    /**
     * Конвертирует inline `<code>...</code>` в backticks.
     *
     * Важно: `pre`‑блоки уже защищены в {@see self::protectPreformattedBlocks()},
     * поэтому здесь обрабатываем только оставшиеся inline‑вставки кода.
     *
     * @param string $html HTML фрагмент
     *
     * @return string HTML с заменёнными inline `<code>`
     */
    private static function convertInlineCodeTags(string $html): string
    {
        return preg_replace_callback('/<code\b[^>]*>([\s\S]*?)<\/code>/i', static function (array $m): string {
            $text = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim($text);
            if ($text === '') {
                return '';
            }
            // Минимальная защита от конфликтов с backtick внутри кода.
            $text = str_replace('`', '\\`', $text);
            return '`' . $text . '`';
        }, $html) ?? $html;
    }

    /**
     * HTML → Markdown обработка изображений (`<img>` → `![alt](src)`).
     *
     * Делается до `strip_tags()`, потому что иначе `<img>` исчезнет и потеряется ссылка на изображение.
     *
     * @param string $sContent HTML content
     *
     * @return string Converted content
     */
    private static function convertHtmlImages(string $sContent): string
    {
        return preg_replace_callback('/<img\b[^>]*>/i', function ($aMatches) {
            $sImgTag = $aMatches[0];

            // Достаём src.
            if (!preg_match('/src\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $sImgTag, $aSrcMatch)) {
                return '';
            }

            $sSrc = '';
            if (isset($aSrcMatch[2]) && $aSrcMatch[2] !== '') {
                $sSrc = $aSrcMatch[2];
            } elseif (isset($aSrcMatch[3]) && $aSrcMatch[3] !== '') {
                $sSrc = $aSrcMatch[3];
            } elseif (isset($aSrcMatch[4])) {
                $sSrc = $aSrcMatch[4];
            }

            // Достаём alt (опционально).
            $sAlt = '';
            if (preg_match('/alt\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $sImgTag, $aAltMatch)) {
                if (isset($aAltMatch[2]) && $aAltMatch[2] !== '') {
                    $sAlt = $aAltMatch[2];
                } elseif (isset($aAltMatch[3]) && $aAltMatch[3] !== '') {
                    $sAlt = $aAltMatch[3];
                } elseif (isset($aAltMatch[4])) {
                    $sAlt = $aAltMatch[4];
                }
            }

            // Декодируем HTML‑сущности.
            $sSrc = html_entity_decode($sSrc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $sAlt = html_entity_decode($sAlt, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Экранируем квадратные скобки, чтобы не сломать markdown‑синтаксис.
            $sAlt = str_replace(['[', ']'], ['\\[', '\\]'], $sAlt);

            return '![' . $sAlt . '](' . $sSrc . ')';
        }, $sContent);
    }

    /**
     * HTML → Markdown обработка ссылок (`<a>` → `[text](href)`).
     *
     * Делается до `strip_tags()`, потому что иначе `<a>` исчезнет без следа.
     *
     * Подход best‑effort:
     * - пытаемся сохранить базовое форматирование текста ссылки (bold/italic);
     * - любые "сложные" вложенные элементы превращаем в обычный текст.
     *
     * @param string $sContent HTML content
     *
     * @return string Converted content
     */
    private static function convertHtmlLinks(string $sContent): string
    {
        return preg_replace_callback('/<a\b[^>]*>(.*?)<\/a>/is', function ($aMatches) {
            $sFullATag = $aMatches[0];
            $sInnerHtml = $aMatches[1];

            // Достаём href (поддерживаем двойные/одинарные кавычки и без кавычек).
            if (!preg_match('/href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $sFullATag, $aHrefMatch)) {
                // Если href нет — возвращаем только текст (убираем внутренние теги).
                return strip_tags($sInnerHtml);
            }

            $sHref = '';
            if (isset($aHrefMatch[2]) && $aHrefMatch[2] !== '') {
                $sHref = $aHrefMatch[2];
            } elseif (isset($aHrefMatch[3]) && $aHrefMatch[3] !== '') {
                $sHref = $aHrefMatch[3];
            } elseif (isset($aHrefMatch[4])) {
                $sHref = $aHrefMatch[4];
            }

            // Внутренний текст ссылки: сохраняем базовое форматирование, потом чистим лишнее.
            $aInnerPatterns = [
                '/<strong[^>]*>(.*?)<\/strong>/is' => '**$1**',
                '/<b[^>]*>(.*?)<\/b>/is' => '**$1**',
                '/<em[^>]*>(.*?)<\/em>/is' => '*$1*',
                '/<i[^>]*>(.*?)<\/i>/is' => '*$1*',
                '/<br\s*\/?>/is' => ' ',
                '/<p[^>]*>(.*?)<\/p>/is' => '$1 ',
            ];

            foreach ($aInnerPatterns as $sPattern => $sReplacement) {
                $sInnerHtml = preg_replace($sPattern, $sReplacement, $sInnerHtml);
            }

            $sText = strip_tags($sInnerHtml);
            $sText = preg_replace('/\s+/', ' ', $sText);
            $sText = trim($sText);

            // Экранируем закрывающую `]`, если она не является частью `](...)`.
            $sText = preg_replace('/\](?!\()/', '\\]', $sText);

            // Декодируем сущности в href.
            $sHref = html_entity_decode($sHref, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return '[' . $sText . '](' . $sHref . ')';
        }, $sContent);
    }

    /**
     * HTML → Markdown handles nested list conversion
     * Identifies and converts HTML nested list structures to Markdown format
     *
     * @param string $sContent Content containing HTML lists
     * @return string Converted content
     */
    private static function nestedLists($sContent)
    {
        // Use stack to correctly parse nested ul/ol tags
        return self::parseNestedLists($sContent, 0);
    }

    /**
     * HTML → Markdown recursively parses nested lists
     * Uses recursion to correctly parse HTML lists containing multiple levels of nesting
     *
     * @param string $sContent HTML content
     * @param int $iLevel Current indentation level
     * @return string Converted Markdown list
     */
    private static function parseNestedLists($sContent, $iLevel)
    {
        $sResult = '';
        $iPos = 0;
        $iLength = strlen($sContent);

        while ($iPos < $iLength) {
            // Find next ul or ol tag
            $iUlPos = strpos($sContent, '<ul', $iPos);
            $iOlPos = strpos($sContent, '<ol', $iPos);

            // Determine the nearest list tag
            $iListPos = false;
            $sListType = '';

            if ($iUlPos !== false && ($iOlPos === false || $iUlPos < $iOlPos)) {
                $iListPos = $iUlPos;
                $sListType = 'ul';
            } elseif ($iOlPos !== false) {
                $iListPos = $iOlPos;
                $sListType = 'ol';
            }

            if ($iListPos === false) {
                // No more lists found, add remaining content
                $sResult .= substr($sContent, $iPos);
                break;
            }

            // Add content before the list
            $sResult .= substr($sContent, $iPos, $iListPos - $iPos);

            // Find corresponding closing tag
            $sStartTag = '<' . $sListType;
            $sEndTag = '</' . $sListType . '>';

            // Find complete list structure
            $aListData = self::extractCompleteList($sContent, $iListPos, $sListType);

            if ($aListData) {
                // Convert list content
                $sListMarkdown = self::convertListToMarkdown($aListData['content'], $iLevel, $sListType);
                $sResult .= $sListMarkdown;
                $iPos = $aListData['end_pos'];
            } else {
                // If parsing fails, skip this tag
                $iPos = $iListPos + strlen($sStartTag);
            }
        }

        return $sResult;
    }

    /**
     * HTML → Markdown extracts complete list structure
     * Uses stack-based approach to match start and end tags, extracts complete structure including nested lists
     *
     * @param string $sContent HTML content
     * @param int $iStartPos List start position
     * @param string $sListType List type ('ul' or 'ol')
     * @return array|false Array containing content and end position, returns false on failure
     */
    private static function extractCompleteList($sContent, $iStartPos, $sListType)
    {
        $sStartTag = '<' . $sListType;
        $sEndTag = '</' . $sListType . '>';

        // Find the end position of the start tag
        $iTagEnd = strpos($sContent, '>', $iStartPos);
        if ($iTagEnd === false) {
            return false;
        }

        $iPos = $iTagEnd + 1;
        $iLevel = 1;
        $iLength = strlen($sContent);

        while ($iPos < $iLength && $iLevel > 0) {
            $iNextStart = strpos($sContent, $sStartTag, $iPos);
            $iNextEnd = strpos($sContent, $sEndTag, $iPos);

            if ($iNextEnd === false) {
                break;
            }

            if ($iNextStart !== false && $iNextStart < $iNextEnd) {
                // Found another start tag
                $iLevel++;
                $iPos = strpos($sContent, '>', $iNextStart) + 1;
            } else {
                // Found closing tag
                $iLevel--;
                if ($iLevel === 0) {
                    // Found matching closing tag
                    $sListContent = substr($sContent, $iTagEnd + 1, $iNextEnd - $iTagEnd - 1);
                    return [
                        'content' => $sListContent,
                        'end_pos' => $iNextEnd + strlen($sEndTag)
                    ];
                }
                $iPos = $iNextEnd + strlen($sEndTag);
            }
        }

        return false;
    }

    /**
     * HTML → Markdown correctly extracts li tag content
     * Handles li tags containing nested structures, ensures correct matching of start and end tags
     *
     * @param string $sListContent List content (inside ul/ol)
     * @return array Extracted li items array
     */
    private static function extractListItems($sListContent)
    {
        $aItems = [];
        $iPos = 0;
        $iLength = strlen($sListContent);

        while ($iPos < $iLength) {
            // Find next <li> tag
            $iLiStart = strpos($sListContent, '<li', $iPos);
            if ($iLiStart === false) {
                break;
            }

            // Find end position of <li> tag
            $iLiTagEnd = strpos($sListContent, '>', $iLiStart);
            if ($iLiTagEnd === false) {
                break;
            }

            // Find corresponding </li> tag
            $iPos = $iLiTagEnd + 1;
            $iLevel = 1;

            while ($iPos < $iLength && $iLevel > 0) {
                $iNextLiStart = strpos($sListContent, '<li', $iPos);
                $iNextLiEnd = strpos($sListContent, '</li>', $iPos);

                if ($iNextLiEnd === false) {
                    break;
                }

                if ($iNextLiStart !== false && $iNextLiStart < $iNextLiEnd) {
                    // Found nested <li>
                    $iLevel++;
                    $iPos = strpos($sListContent, '>', $iNextLiStart) + 1;
                } else {
                    // Found </li>
                    $iLevel--;
                    if ($iLevel === 0) {
                        // Found matching </li>
                        $sLiContent = substr($sListContent, $iLiTagEnd + 1, $iNextLiEnd - $iLiTagEnd - 1);
                        $aItems[] = [1 => $sLiContent]; // Simulate original format
                        $iPos = $iNextLiEnd + 5; // Skip </li>
                        break;
                    }
                    $iPos = $iNextLiEnd + 5;
                }
            }
        }

        return $aItems;
    }

    /**
     * HTML → Markdown converts list content to Markdown
     * Converts parsed HTML list items to Markdown list format, supports nested structures
     *
     * @param string $sListContent List content
     * @param int $iLevel Indentation level
     * @param string $sListType List type ('ul' or 'ol')
     * @return string Markdown formatted list
     */
    private static function convertListToMarkdown($sListContent, $iLevel, $sListType)
    {
        // Use correct method to parse li tags (handles nested structures)
        $aMatches = self::extractListItems($sListContent);

        $sMarkdown = '';
        $iCounter = 1;
        $sIndent = str_repeat('  ', $iLevel);

        foreach ($aMatches as $aMatch) {
            $sLiContent = $aMatch[1];

            // Check if contains nested list
            if (preg_match('/<(ul|ol)[^>]*>.*?<\/\1>/is', $sLiContent)) {
                // Separate text and nested list
                $sText = preg_replace('/<(ul|ol)[^>]*>.*?<\/\1>/is', '', $sLiContent);
                $sText = self::processListItemContent($sText);

                // Extract nested list portion
                preg_match('/<(ul|ol)[^>]*>.*?<\/\1>/is', $sLiContent, $aNestedMatch);
                $sNestedListHtml = $aNestedMatch[0];

                // Process nested list, ensure correct indentation
                $sNestedContent = self::parseNestedLists($sNestedListHtml, $iLevel + 1);

                // Generate marker
                $sMarker = ($sListType === 'ol') ? $sIndent . $iCounter . '. ' : $sIndent . '- ';
                $sMarkdown .= $sMarker . $sText . "\n" . $sNestedContent;
            } else {
                // Plain text content
                $sText = self::processListItemContent($sLiContent);
                $sMarker = ($sListType === 'ol') ? $sIndent . $iCounter . '. ' : $sIndent . '- ';
                $sMarkdown .= $sMarker . $sText . "\n";
            }

            if ($sListType === 'ol') {
                $iCounter++;
            }
        }

        return $sMarkdown;
    }

    /**
     * HTML → Markdown processes p tags within list items
     * Converts p tags inside li tags to appropriate text format, prevents formatting errors
     *
     * @param string $sContent li tag content
     * @return string Processed plain text content
     */
    private static function processListItemContent($sContent)
    {
        // Process p tags first, convert to line breaks
        $sContent = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n", $sContent);

        // Remove excessive line breaks and whitespace
        $sContent = preg_replace("/\n+/", "\n", $sContent);

        // Remove other HTML tags
        $sContent = strip_tags(trim($sContent));

        // Convert line breaks to spaces (in markdown lists, multi-line content within same item is separated by spaces)
        $sContent = str_replace("\n", " ", $sContent);

        // Remove excessive spaces
        $sContent = preg_replace("/\s+/", " ", $sContent);

        return trim($sContent);
    }

    /**
     * HTML → Markdown converts HTML tables to Markdown tables
     * Identifies HTML table tags and converts to Markdown table format
     *
     * @param string $sContent Content containing HTML tables
     * @return string Converted content
     */
    private static function convertHtmlTables($sContent)
    {
        // Use regular expression to match entire table
        return preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', function ($aMatches) {
            return self::parseHtmlTable($aMatches[1]);
        }, $sContent);
    }

    /**
     * HTML → Markdown parses single HTML table
     * Parses HTML table structure (thead, tbody, tr, th, td) and converts to Markdown table format
     *
     * @param string $sTableContent table tag content
     * @return string Markdown formatted table
     */
    private static function parseHtmlTable($sTableContent)
    {
        // Remove thead and tbody tags, preserve content
        $sTableContent = preg_replace('/<\/?t(head|body)[^>]*>/i', '', $sTableContent);

        // Extract all tr tags
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $sTableContent, $aRows, PREG_SET_ORDER);

        if (empty($aRows)) {
            return '';
        }

        $aMarkdownRows = [];
        $bFirstRowIsHeader = false;

        // Check if first row contains th tags
        if (preg_match('/<th[^>]*>/', $aRows[0][1])) {
            $bFirstRowIsHeader = true;
        }

        foreach ($aRows as $iIndex => $aRow) {
            $sRowContent = $aRow[1];

            // Extract all td or th tags
            preg_match_all('/<(td|th)[^>]*>(.*?)<\/\1>/is', $sRowContent, $aCells, PREG_SET_ORDER);

            $aCellContents = [];
            foreach ($aCells as $aCell) {
                // Process cell content, remove HTML tags and clean whitespace
                $sCellContent = self::processCellContent($aCell[2]);
                $aCellContents[] = $sCellContent;
            }

            if (!empty($aCellContents)) {
                // Build Markdown table row
                $sMarkdownRow = '| ' . implode(' | ', $aCellContents) . ' |';
                $aMarkdownRows[] = $sMarkdownRow;

                // If this is header row, add separator
                if ($iIndex === 0 && $bFirstRowIsHeader) {
                    $aSeparator = array_fill(0, count($aCellContents), '---');
                    $sSeparatorRow = '| ' . implode(' | ', $aSeparator) . ' |';
                    $aMarkdownRows[] = $sSeparatorRow;
                }
            }
        }

        // If first row is not header, treat first row as header and add separator
        if (!$bFirstRowIsHeader && !empty($aMarkdownRows)) {
            $iColCount = substr_count($aMarkdownRows[0], '|') - 1;
            $aSeparator = array_fill(0, $iColCount, '---');
            $sSeparatorRow = '| ' . implode(' | ', $aSeparator) . ' |';
            array_splice($aMarkdownRows, 1, 0, [$sSeparatorRow]);
        }

        return "\n" . implode("\n", $aMarkdownRows) . "\n\n";
    }

    /**
     * HTML → Markdown processes table cell content
     * Cleans HTML tags within table cells, preserves basic formatting, avoids Markdown syntax conflicts
     *
     * @param string $sContent Cell content
     * @return string Cleaned cell content
     */
    private static function processCellContent($sContent)
    {
        // Process internal HTML tags first (preserve formatting)
        $aInnerPatterns = [
            '/<strong[^>]*>(.*?)<\/strong>/is' => '**$1**',
            '/<b[^>]*>(.*?)<\/b>/is' => '**$1**',
            '/<em[^>]*>(.*?)<\/em>/is' => '*$1*',
            '/<i[^>]*>(.*?)<\/i>/is' => '*$1*',
            '/<a[^>]*href=[\"\'](.*?)[\"\'][^>]*>(.*?)<\/a>/is' => '[$2]($1)',
            '/<br\s*\/?>/is' => ' ',
            '/<p[^>]*>(.*?)<\/p>/is' => '$1 ',
        ];

        foreach ($aInnerPatterns as $sPattern => $sReplacement) {
            $sContent = preg_replace($sPattern, $sReplacement, $sContent);
        }

        // Remove remaining HTML tags
        $sContent = strip_tags($sContent);

        // Clean whitespace and line breaks
        $sContent = preg_replace('/\s+/', ' ', $sContent);
        $sContent = trim($sContent);

        // Avoid Markdown table syntax conflicts
        $sContent = str_replace('|', '\\|', $sContent);

        return $sContent;
    }

    /**
     * Markdown → HTML main conversion method
     * Converts Markdown content to HTML format
     *
     * @param string $sMarkdownContent Markdown content
     * @return string HTML formatted text
     */
    public static function markdownToHtml($sMarkdownContent)
    {
        // Process tables first
        $sMarkdownContent = self::markdownTables($sMarkdownContent);

        // Process links and images first
        $aReplacePatterns = [
            // Process image syntax ![alt](url)
            '/!\[([^\]]*)\]\(([^)]+)\)/' => '<img src="$2" alt="$1" />',
            // Process link syntax [text](url)
            '/\[(.*?)\]\((.*?)\)/' => '<a href="$2">$1</a>',
            // Process headings
            '/^###### (.*)$/m' => '<h6>$1</h6>',
            '/^##### (.*)$/m' => '<h5>$1</h5>',
            '/^#### (.*)$/m' => '<h4>$1</h4>',
            '/^### (.*)$/m' => '<h3>$1</h3>',
            '/^## (.*)$/m' => '<h2>$1</h2>',
            '/^# (.*)$/m' => '<h1>$1</h1>',
            // Bold and italic (order matters)
            '/\*\*\*(.*?)\*\*\*/s' => '<strong><em>$1</em></strong>',
            '/\*\*(.*?)\*\*/s' => '<strong>$1</strong>',
            '/\*(.*?)\*/s' => '<em>$1</em>',
            // Don't process lists yet, handle with specialized method later
        ];

        // Perform basic conversion first
        foreach ($aReplacePatterns as $k1 => $v1) {
            $sMarkdownContent = preg_replace($k1, $v1, $sMarkdownContent);
        }

        // Process lists (including nested lists)
        $sMarkdownContent = self::markdownLists($sMarkdownContent);

        // Convert paragraphs (non-heading/list/table) to <p>
        $aLines = preg_split("/\r\n|\n|\r/", $sMarkdownContent);
        $sHtmlContent = '';

        foreach ($aLines as $sLine) {
            $sTrimmed = trim($sLine);
            if ($sTrimmed === '') {
                continue;
            }

            // If not starting with HTML tag (avoid wrapping headings, ul, table repeatedly)
            if (!preg_match('/^<.*>/', $sTrimmed)) {
                $sHtmlContent .= '<p>' . $sTrimmed . '</p>' . "\n";
            } else {
                $sHtmlContent .= $sTrimmed . "\n";
            }
        }

        return trim($sHtmlContent);
    }
    /**
     * Markdown → HTML converts Markdown lists to HTML
     * Parses Markdown formatted lists and converts to HTML ul/ol structure, supports nested lists
     *
     * @param string $sContent Markdown content
     * @return string Content containing HTML lists
     */
    private static function markdownLists($sContent)
    {
        $aLines = preg_split("/\r\n|\n|\r/", $sContent);
        $aProcessedLines = [];

        for ($i = 0; $i < count($aLines); $i++) {
            $sLine = $aLines[$i];

            // Check if it's a list item
            if (preg_match('/^(\s*)-\s+(.*)$/', $sLine, $aMatches)) {
                $iIndent = strlen($aMatches[1]); // Calculate indentation character count
                $sItemContent = trim($aMatches[2]);

                // Determine current level (every 2 spaces is one level)
                $iLevel = intval($iIndent / 2);

                // Mark as list item, record level and content
                $aProcessedLines[] = [
                    'type' => 'list_item',
                    'level' => $iLevel,
                    'content' => $sItemContent
                ];
            } else {
                // Not a list item
                $aProcessedLines[] = [
                    'type' => 'text',
                    'content' => $sLine
                ];
            }
        }

        // Convert to HTML
        return self::nestedListHtml($aProcessedLines);
    }
    /**
     * Markdown → HTML builds nested list HTML structure
     * Builds correct HTML nested list structure based on parsed Markdown list data
     *
     * @param array $aProcessedLines Parsed list line data
     * @return string HTML formatted nested list
     */
    private static function nestedListHtml($aProcessedLines)
    {
        $aResult = [];
        $aOpenLevels = []; // Record opened levels
        $bLastWasListItem = false;

        for ($i = 0; $i < count($aProcessedLines); $i++) {
            $aLine = $aProcessedLines[$i];

            if ($aLine['type'] === 'list_item') {
                $iLevel = $aLine['level'];
                $sContent = $aLine['content'];

                // Close lists deeper than current level
                while (!empty($aOpenLevels) && end($aOpenLevels) > $iLevel) {
                    array_pop($aOpenLevels);
                    $aResult[] = '</ul>';
                    if (!empty($aOpenLevels)) {
                        $aResult[] = '</li>';
                    }
                }

                // If need to open new level
                if (empty($aOpenLevels) || end($aOpenLevels) < $iLevel) {
                    if (!empty($aOpenLevels) && $bLastWasListItem) {
                        // Remove previous </li>, prepare to add <ul> inside it
                        $sLastItem = array_pop($aResult);
                        $sLastItem = str_replace('</li>', '', $sLastItem);
                        $aResult[] = $sLastItem;
                        $aResult[] = '<ul>';
                    } else {
                        // First level list or independent list
                        $aResult[] = '<ul>';
                    }
                    $aOpenLevels[] = $iLevel;
                }

                $aResult[] = '<li>' . $sContent . '</li>';
                $bLastWasListItem = true;
            } else {
                // Not a list item, close all lists
                while (!empty($aOpenLevels)) {
                    array_pop($aOpenLevels);
                    $aResult[] = '</ul>';
                    if (!empty($aOpenLevels)) {
                        $aResult[] = '</li>';
                    }
                }

                // Add original line (if not empty line)
                if (trim($aLine['content']) !== '') {
                    $aResult[] = $aLine['content'];
                }
                $bLastWasListItem = false;
            }
        }

        // Close remaining lists
        while (!empty($aOpenLevels)) {
            array_pop($aOpenLevels);
            $aResult[] = '</ul>';
            if (!empty($aOpenLevels)) {
                $aResult[] = '</li>';
            }
        }

        return implode("\n", $aResult);
    }

    /**
     * Markdown → HTML converts Markdown tables to HTML
     * Parses Markdown table syntax and converts to HTML table structure
     *
     * @param string $sContent Markdown content
     * @return string Content containing HTML tables
     */
    private static function markdownTables($sContent)
    {
        // Use regular expression to match entire table
        $sContent = preg_replace_callback('/^(\|.*\|)\s*\n(\|[-:| ]+\|)\s*\n((?:\|.*\|\s*\n?)*)/m', function ($aMatches) {
            $sHeaderRow = trim($aMatches[1]);
            $sAlignRow = trim($aMatches[2]);
            $sDataRows = trim($aMatches[3]);

            // Parse header row
            $aHeaders = array_map('trim', explode('|', trim($sHeaderRow, '|')));

            // Parse alignment
            $aAligns = array_map('trim', explode('|', trim($sAlignRow, '|')));
            $aAlignments = [];

            foreach ($aAligns as $sAlign) {
                if (strpos($sAlign, ':') === 0 && substr($sAlign, -1) === ':') {
                    $aAlignments[] = 'center';
                } elseif (substr($sAlign, -1) === ':') {
                    $aAlignments[] = 'right';
                } else {
                    $aAlignments[] = 'left';
                }
            }

            // Build table HTML
            $sTableHtml = '<table width="100%" border="0" cellpadding="0" cellspacing="0">';

            // Table header
            $sTableHtml .= '<thead><tr>';
            foreach ($aHeaders as $iIndex => $sHeader) {
                $sAlign = isset($aAlignments[$iIndex]) ? ' style="text-align: ' . $aAlignments[$iIndex] . '"' : '';
                $sTableHtml .= '<th' . $sAlign . '>' . trim($sHeader) . '</th>';
            }
            $sTableHtml .= '</tr></thead>';

            // Table body
            if (!empty($sDataRows)) {
                $sTableHtml .= '<tbody>';
                $aDataLines = array_filter(explode("\n", $sDataRows));
                foreach ($aDataLines as $sDataLine) {
                    $sDataLine = trim($sDataLine);
                    if (empty($sDataLine)) {
                        continue;
                    }

                    $aCells = array_map('trim', explode('|', trim($sDataLine, '|')));
                    $sTableHtml .= '<tr>';
                    foreach ($aCells as $iIndex => $sCell) {
                        $sAlign = isset($aAlignments[$iIndex]) ? ' style="text-align: ' . $aAlignments[$iIndex] . '"' : '';
                        $sTableHtml .= '<td' . $sAlign . '>' . trim($sCell) . '</td>';
                    }
                    $sTableHtml .= '</tr>';
                }
                $sTableHtml .= '</tbody>';
            }

            $sTableHtml .= '</table>';

            return $sTableHtml;
        }, $sContent);

        return $sContent;
    }
}
