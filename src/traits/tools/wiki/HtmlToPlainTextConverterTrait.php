<?php
// src/app/modules/neuron/traits/tools/wiki/HtmlToPlainTextConverterTrait.php

namespace app\modules\neuron\traits\tools\wiki;

/**
 * Трейт для преобразования HTML в форматированный plain текст.
 * Обрабатывает абзацы, таблицы, ссылки и специальные символы.
 * Фильтрует служебные элементы: оглавление, примечания, ссылки на редактирование.
 */
trait HtmlToPlainTextConverterTrait
{
    /**
     * Преобразует HTML в форматированный plain текст.
     *
     * @param string $html HTML контент
     * @return array Массив с ключами 'text' (plain текст) и 'links' (массив ссылок)
     */
    protected function convertHtmlToPlainText(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        $links = [];
        $plainText = $this->processDomNode($dom->documentElement, $links);

        // Нормализуем пробелы и символы
        $plainText = $this->normalizeText($plainText);

        return [
            'text' => $plainText,
            'links' => $links,
        ];
    }

    /**
     * Обрабатывает DOM узел рекурсивно.
     *
     * @param \DOMNode $node DOM узел
     * @param array    $links Ссылки (передается по ссылке)
     *
     * @return string Текстовое представление узла
     */
    protected function processDomNode(\DOMNode $node, array &$links): string
    {
        $result = '';

        // Проверяем, является ли узел служебным (пропускаем обработку)
        if ($this->shouldSkipNode($node)) {
            return '';
        }

        if ($node->nodeType === XML_TEXT_NODE) {
            $result = $node->textContent;
        } elseif ($node->nodeType === XML_ELEMENT_NODE) {
            $nodeName = strtolower($node->nodeName);

            // Проверяем, не является ли элемент служебным контейнером
            if ($this->isUtilityContainer($node)) {
                return '';
            }

            switch ($nodeName) {
                case 'p':
                    $innerText = $this->processChildren($node, $links);
                    if (trim($innerText) !== '' && !$this->isEditSectionParagraph($node)) {
                        $result = "\n\n" . trim($innerText) . "\n\n";
                    }
                    break;

                case 'table':
                    if (!$this->isUtilityTable($node)) {
                        $tableText = $this->processTable($node, $links);
                        if (trim($tableText) !== '') {
                            $result = "\n\n" . $tableText . "\n\n";
                        }
                    }
                    break;

                case 'tr':
                    $result = $this->processChildren($node, $links) . "\n";
                    break;

                case 'td':
                case 'th':
                    $cellText = $this->processChildren($node, $links);
                    $result = '| ' . trim($cellText) . ' ';
                    break;

                case 'a':
                    $href = $node->getAttribute('href');
                    $linkText = $this->processChildren($node, $links);

                    // Пропускаем служебные ссылки (редактирование, оглавление, примечания)
                    if ($this->isUtilityLink($href, $node)) {
                        break;
                    }

                    if ($href && $linkText && trim($linkText) !== '') {
                        $absoluteUrl = $this->makeAbsoluteUrl($href);
                        $links[$absoluteUrl] = [
                            'text'         => trim($linkText),
                            'original_url' => $href,
                            'absolute_url' => $absoluteUrl,
                            'status'       => 'pending', // статус будет обновлен при проверке
                        ];
                        $result = trim($linkText);
                    } else {
                        $result = trim($linkText);
                    }
                    break;

                case 'br':
                    $result = "\n";
                    break;

                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $innerText = $this->processChildren($node, $links);
                    if (trim($innerText) !== '') {
                        $result = "\n\n" . str_repeat('#', (int) substr($nodeName, 1)) . ' '
                            . trim($innerText) . "\n\n";
                    }
                    break;

                case 'ul':
                case 'ol':
                    // Пропускаем только явные служебные списки (оглавление)
                    if (!$this->isTableOfContents($node)) {
                        $result = $this->processChildren($node, $links) . "\n\n";
                    }
                    break;

                case 'li':
                    $innerText = $this->processChildren($node, $links);
                    if (trim($innerText) !== '') {
                        $result = '* ' . trim($innerText) . "\n";
                    }
                    break;

                case 'div':
                    $innerText = $this->processChildren($node, $links);
                    if (trim($innerText) !== '' && !$this->isUtilityReferences($node)) {
                        $result = "\n" . trim($innerText) . "\n";
                    }
                    break;

                case 'nav':
                case 'aside':
                    // Пропускаем навигационные блоки
                    if (!$this->isMainNavigation($node)) {
                        $result = $this->processChildren($node, $links);
                    }
                    break;

                case 'span':
                    // Пропускаем span с классом mw-editsection
                    if (!$this->isEditSection($node)) {
                        $result = $this->processChildren($node, $links);
                    }
                    break;

                default:
                    $result = $this->processChildren($node, $links);
                    break;
            }
        }

        return $result;
    }

    /**
     * Обрабатывает дочерние узлы.
     *
     * @param \DOMNode $node  Родительский узел
     * @param array    $links Ссылки
     *
     * @return string Текст дочерних узлов
     */
    protected function processChildren(\DOMNode $node, array &$links): string
    {
        $result = '';
        foreach ($node->childNodes as $child) {
            $result .= $this->processDomNode($child, $links);
        }

        return $result;
    }

    /**
     * Определяет, нужно ли пропустить узел.
     *
     * @param \DOMNode $node DOM узел
     *
     * @return bool True, если узел нужно пропустить
     */
    protected function shouldSkipNode(\DOMNode $node): bool
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }

        $class = strtolower($node->getAttribute('class'));
        $id    = strtolower($node->getAttribute('id'));

        // Только явные служебные элементы, которые должны быть полностью пропущены
        $skipPatterns = [
            'mw-editsection',
            'mw-cite-backlink',
            'reference-text',
            'noprint',
            'nomobile',
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_contains($class, $pattern) || str_contains($id, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Определяет, является ли элемент служебным контейнером.
     *
     * @param \DOMElement $element DOM элемент
     *
     * @return bool True, если контейнер служебный
     */
    protected function isUtilityContainer(\DOMElement $element): bool
    {
        $class = strtolower($element->getAttribute('class'));
        $id    = strtolower($element->getAttribute('id'));

        // Только явные служебные контейнеры
        $containerPatterns = [
            'toc',              // Оглавление
            'catlinks',         // Категории
            'reflist',          // Список примечаний
            'references',       // Примечания
            'navbox',           // Навигационная панель
            'metadata',         // Метаданные
            'mw-normal-catlinks',
            'mw-hidden-catlinks',
            'mw-references-columns',
        ];

        foreach ($containerPatterns as $pattern) {
            if (str_contains($class, $pattern) || str_contains($id, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Определяет, является ли таблица служебной.
     *
     * @param \DOMElement $table Элемент таблицы
     *
     * @return bool True, если таблица служебная
     */
    protected function isUtilityTable(\DOMElement $table): bool
    {
        $class = strtolower($table->getAttribute('class'));
        $id    = strtolower($table->getAttribute('id'));

        // Служебные таблицы (навигация, категории)
        $tablePatterns = [
            'navbox',
            'catlinks',
            'metadata',
            'ambox',
            'mbox',
        ];

        foreach ($tablePatterns as $pattern) {
            if (str_contains($class, $pattern) || str_contains($id, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Определяет, является ли параграф секцией редактирования.
     *
     * @param \DOMElement $paragraph Элемент параграфа
     *
     * @return bool True, если параграф содержит ссылки на редактирование
     */
    protected function isEditSectionParagraph(\DOMElement $paragraph): bool
    {
        $class = strtolower($paragraph->getAttribute('class'));

        // Параграфы с редактированием
        $editPatterns = [
            'mw-editsection',
            'mw-editsection-like',
        ];

        foreach ($editPatterns as $pattern) {
            if (str_contains($class, $pattern)) {
                return true;
            }
        }

        // Проверяем дочерние элементы
        foreach ($paragraph->getElementsByTagName('span') as $span) {
            $spanClass = strtolower($span->getAttribute('class'));
            foreach ($editPatterns as $pattern) {
                if (str_contains($spanClass, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Определяет, является ли span элементом редактирования.
     *
     * @param \DOMElement $span Элемент span
     *
     * @return bool True, если span для редактирования
     */
    protected function isEditSection(\DOMElement $span): bool
    {
        $class = strtolower($span->getAttribute('class'));

        return str_contains($class, 'mw-editsection')
            || str_contains($class, 'mw-editsection-like');
    }

    /**
     * Определяет, является ли ссылка служебной.
     *
     * @param string      $href URL ссылки
     * @param \DOMElement $link Элемент ссылки
     *
     * @return bool True, если ссылка служебная
     */
    protected function isUtilityLink(string $href, \DOMElement $link): bool
    {
        // Пропускаем якорные ссылки (внутренние переходы)
        if (strpos($href, '#') === 0) {
            return true;
        }

        // Пропускаем ссылки на редактирование
        if (strpos($href, 'action=edit') !== false) {
            return true;
        }

        // Проверяем класс ссылки
        $class        = strtolower($link->getAttribute('class'));
        $linkPatterns = [
            'mw-editsection',
            'mw-editsection-like',
            'mw-cite-backlink',
            'external',
        ];

        foreach ($linkPatterns as $pattern) {
            if (str_contains($class, $pattern)) {
                return true;
            }
        }

        // Проверяем, является ли ссылка на служебное пространство имен Wikipedia
        if ($this->isWikipediaNamespaceLink($href)) {
            return true;
        }

        // Проверяем, является ли ссылка на Викисклад или Wikimedia Commons
        if (strpos($href, 'commons.wikimedia.org') !== false
            || strpos($href, 'wikimedia.org') !== false
            || strpos($href, 'wikimediafoundation.org') !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * Проверяет, является ли ссылка на служебное пространство имен Wikipedia.
     *
     * @param string $href URL ссылки
     *
     * @return bool True, если ссылка на служебное пространство имен
     */
    protected function isWikipediaNamespaceLink(string $href): bool
    {
        // Проверяем только путь /wiki/ с двоеточием (служебные пространства)
        if (preg_match('/\/wiki\/([^\/]+):/i', $href, $matches)) {
            $namespace = strtolower($matches[1]);

            // Служебные пространства имен
            $utilityNamespaces = [
                'file',
                'image',
                'media',
                'special',
                'portal',
                'category',
                'help',
                'template',
                'module',
                'mediawiki',
                'user',
                'talk',
                'wikipedia',
                'wp',
                'project',
                'draft',
            ];

            return in_array($namespace, $utilityNamespaces, true);
        }

        return false;
    }

    /**
     * Определяет, является ли список оглавлением.
     *
     * @param \DOMElement $list Элемент списка
     *
     * @return bool True, если список является оглавлением
     */
    protected function isTableOfContents(\DOMElement $list): bool
    {
        $class = strtolower($list->getAttribute('class'));

        return str_contains($class, 'toc');
    }

    /**
     * Определяет, является ли div блоком примечаний/ссылок.
     *
     * @param \DOMElement $div Элемент div
     *
     * @return bool True, если div с примечаниями
     */
    protected function isUtilityReferences(\DOMElement $div): bool
    {
        $class = strtolower($div->getAttribute('class'));

        return str_contains($class, 'reflist')
            || str_contains($class, 'references');
    }

    /**
     * Определяет, является ли элемент основной навигацией.
     *
     * @param \DOMElement $element Элемент
     *
     * @return bool True, если это основная навигация
     */
    protected function isMainNavigation(\DOMElement $element): bool
    {
        $class = strtolower($element->getAttribute('class'));
        $role  = strtolower($element->getAttribute('role'));

        return str_contains($class, 'navigation')
            || str_contains($class, 'nav')
            || $role === 'navigation';
    }

    /**
     * Обрабатывает таблицу и преобразует её в markdown-формат.
     *
     * @param \DOMElement $table Элемент таблицы
     * @param array       $links Ссылки
     *
     * @return string Таблица в markdown-формате
     */
    protected function processTable(\DOMElement $table, array &$links): string
    {
        $rows       = [];
        $maxColumns = 0;

        // Собираем строки
        foreach ($table->getElementsByTagName('tr') as $rowIndex => $tr) {
            $row = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cellText = $this->processChildren($cell, $links);
                    $row[]    = trim($cellText);
                }
            }
            $rows[]     = $row;
            $maxColumns = max($maxColumns, count($row));
        }

        if (empty($rows)) {
            return '';
        }

        // Определяем ширину столбцов
        $columnWidths = array_fill(0, $maxColumns, 0);
        foreach ($rows as $row) {
            for ($i = 0; $i < $maxColumns; $i++) {
                $cell            = $row[$i] ?? '';
                $columnWidths[$i] = max($columnWidths[$i], mb_strlen($cell));
            }
        }

        // Формируем markdown таблицу
        $tableText = '';
        foreach ($rows as $rowIndex => $row) {
            $tableText .= '|';
            for ($i = 0; $i < $maxColumns; $i++) {
                $cell    = $row[$i] ?? '';
                $padding = $columnWidths[$i] - mb_strlen($cell);
                $tableText .= ' ' . $cell . str_repeat(' ', $padding) . ' |';
            }
            $tableText .= "\n";

            // Добавляем разделитель после заголовка
            if ($rowIndex === 0) {
                $tableText .= '|';
                for ($i = 0; $i < $maxColumns; $i++) {
                    $tableText .= ' ' . str_repeat('-', $columnWidths[$i]) . ' |';
                }
                $tableText .= "\n";
            }
        }

        return rtrim($tableText);
    }

    /**
     * Преобразует относительный URL в абсолютный.
     *
     * @param string $url Относительный или абсолютный URL
     *
     * @return string Абсолютный URL
     */
    protected function makeAbsoluteUrl(string $url): string
    {
        if (preg_match('/^(https?:)?\/\//', $url)) {
            return $url;
        }

        // Для Wikipedia ссылок
        if (strpos($url, '/wiki/') === 0) {
            return 'https://en.wikipedia.org' . $url;
        }

        return $url;
    }

    /**
     * Нормализует текст: заменяет специальные символы пробелов, удаляет лишние пробелы.
     *
     * @param string $text Исходный текст
     *
     * @return string Нормализованный текст
     */
    protected function normalizeText(string $text): string
    {
        // Заменяем специальные символы пробелов на обычный пробел
        $replacements = [
            "\u{00A0}" => ' ', // неразрывный пробел
            "\u{200B}" => ' ', // пробел нулевой ширины
            "\u{200C}" => ' ', // нулевой не-соединитель
            "\u{200D}" => ' ', // нулевой соединитель
            "\u{FEFF}" => ' ', // нулевой пробел без ширины
            "\u{00AD}" => '',  // мягкий перенос
        ];

        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        // Убираем лишние переводы строк (оставляем максимум 2 подряд)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Убираем лишние пробелы в начале и конце строк
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text  = implode("\n", $lines);

        // Убираем пустые строки в начале и конце
        return trim($text);
    }
}


