<?php
// src/app/modules/neuron/enums/ContentSourceType.php

namespace app\modules\neuron\enums;

/**
 * Перечисление типов источников контента.
 * Определяет все возможные типы источников, которые могут обрабатываться системой.
 * 
 * @method static self WIKIPEDIA()     Статья из Wikipedia
 * @method static self RUWIKI()        Статья из RuWiki
 * @method static self GENERIC()       Произвольная веб-страница
 * @method static self SEARXNG()       Результат поиска через SearXNG
 * @method static self OTHER_WIKI()    Другие вики-энциклопедии
 */
enum ContentSourceType: string
{
    /**
     * Статья из Wikipedia (все языковые версии)
     */
    case WIKIPEDIA = 'wikipedia';
    
    /**
     * Статья из RuWiki (ruwiki.ru)
     */
    case RUWIKI = 'ruwiki';
    
    /**
     * Произвольная веб-страница (любой другой сайт)
     */
    case GENERIC = 'generic';
    
    /**
     * Результат поиска через SearXNG (мета-поисковая система)
     * Этот тип указывает, что статья была найдена через SearXNG,
     * но фактический источник может быть любым (Wikipedia, другие сайты и т.д.)
     */
    case SEARXNG = 'searxng';
    
    /**
     * Статья из других вики-энциклопедий, кроме Wikipedia и RuWiki
     * Например: Wiktionary, Wikibooks, местные вики-проекты
     */
    case OTHER_WIKI = 'other_wiki';
    
    /**
     * Возвращает читаемое название типа источника.
     *
     * @return string Человеко-читаемое название
     */
    public function getLabel(): string
    {
        return match($this) {
            self::WIKIPEDIA => 'Wikipedia',
            self::RUWIKI => 'RuWiki',
            self::GENERIC => 'Generic Website',
            self::SEARXNG => 'SearXNG Search',
            self::OTHER_WIKI => 'Other Wiki',
        };
    }
    
    /**
     * Возвращает описание типа источника.
     *
     * @return string Подробное описание
     */
    public function getDescription(): string
    {
        return match($this) {
            self::WIKIPEDIA => 'Статья из свободной энциклопедии Wikipedia',
            self::RUWIKI => 'Статьи из русскоязычной вики-энциклопедии RuWiki',
            self::GENERIC => 'Произвольная веб-страница с любого сайта',
            self::SEARXNG => 'Результат, полученный через мета-поисковую систему SearXNG. '
                           . 'SearXNG объединяет результаты из множества источников, '
                           . 'обеспечивая приватность и отсутствие цензуры.',
            self::OTHER_WIKI => 'Статья из других вики-энциклопедий '
                              . '(например, Wiktionary, Wikibooks, локальные вики-проекты)',
        };
    }
    
    /**
     * Проверяет, является ли источник вики-энциклопедией.
     * Включает Wikipedia, RuWiki и другие вики-проекты.
     *
     * @return bool True, если источник является вики-энциклопедией
     */
    public function isWiki(): bool
    {
        return match($this) {
            self::WIKIPEDIA, self::RUWIKI, self::OTHER_WIKI => true,
            self::GENERIC, self::SEARXNG => false,
        };
    }
    
    /**
     * Проверяет, является ли источник поисковой системой.
     * Поисковые системы (как SearXNG) не содержат контент напрямую,
     * а предоставляют доступ к контенту из других источников.
     *
     * @return bool True, если источник является поисковой системой
     */
    public function isSearchEngine(): bool
    {
        return match($this) {
            self::SEARXNG => true,
            self::WIKIPEDIA, self::RUWIKI, self::GENERIC, self::OTHER_WIKI => false,
        };
    }
    
    /**
     * Проверяет, является ли источник специализированным (не generic).
     * Специализированные источники имеют собственные загрузчики.
     * SearXNG является специализированным, так как имеет свой поисковик.
     *
     * @return bool True, если источник специализированный
     */
    public function isSpecialized(): bool
    {
        return match($this) {
            self::WIKIPEDIA, self::RUWIKI, self::SEARXNG, self::OTHER_WIKI => true,
            self::GENERIC => false,
        };
    }
    
    /**
     * Проверяет, является ли источник исходным контентом.
     * Исходные источники содержат контент напрямую (статьи, страницы).
     * Поисковые системы не являются исходными источниками.
     *
     * @return bool True, если источник содержит исходный контент
     */
    public function isOriginalContent(): bool
    {
        return match($this) {
            self::WIKIPEDIA, self::RUWIKI, self::GENERIC, self::OTHER_WIKI => true,
            self::SEARXNG => false,
        };
    }
    
    /**
     * Возвращает базовый URL для данного типа источника.
     * Для SearXNG возвращает null, так как нет единого базового URL
     * (используются различные публичные экземпляры).
     *
     * @param string|null $language Язык для Wikipedia (например, 'en', 'ru', 'de')
     * @return string|null Базовый URL или null для generic и search engine источников
     */
    public function getBaseUrl(?string $language = null): ?string
    {
        return match($this) {
            self::WIKIPEDIA => $this->getWikipediaBaseUrl($language),
            self::RUWIKI => 'https://ru.ruwiki.ru',
            self::OTHER_WIKI => null, // Нет единого базового URL для всех других вики
            self::GENERIC, self::SEARXNG => null,
        };
    }
    
    /**
     * Возвращает базовый URL для Wikipedia с учетом языка.
     *
     * @param string|null $language Язык Wikipedia (например, 'en', 'ru', 'de')
     * @return string Базовый URL Wikipedia
     */
    private function getWikipediaBaseUrl(?string $language = null): string
    {
        $lang = $language ?: 'en';
        return "https://{$lang}.wikipedia.org";
    }
    
    /**
     * Возвращает домен(ы), соответствующие данному типу источника.
     * Для SearXNG возвращает известные публичные экземпляры.
     *
     * @return string[] Массив доменов
     */
    public function getDomains(): array
    {
        return match($this) {
            self::WIKIPEDIA => ['wikipedia.org', 'wikimedia.org'],
            self::RUWIKI => ['ruwiki.ru', 'ruwiki.org'],
            self::SEARXNG => [
                'searx.be',
                'search.unlocked.link',
                'searx.space',
                'searx.nixnet.services',
                'searx.info',
                'searx.thegpm.org',
                'searx.tiekoetter.com',
            ],
            self::OTHER_WIKI => ['wiki.'], // Общий паттерн для вики
            self::GENERIC => [], // Для generic нет специфичных доменов
        };
    }
    
    /**
     * Определяет тип источника по URL.
     * Анализирует домен URL и возвращает соответствующий тип источника.
     * Добавлена поддержка SearXNG и других вики.
     *
     * @param string $url URL для анализа
     * @return self Тип источника
     */
    public static function fromUrl(string $url): self
    {
        $host = parse_url($url, PHP_URL_HOST);
        
        if (!$host) {
            return self::GENERIC;
        }
        
        $host = strtolower($host);
        
        // Проверяем Wikipedia
        foreach (self::WIKIPEDIA->getDomains() as $domain) {
            if (str_contains($host, $domain)) {
                return self::WIKIPEDIA;
            }
        }
        
        // Проверяем RuWiki
        foreach (self::RUWIKI->getDomains() as $domain) {
            if (str_contains($host, $domain)) {
                return self::RUWIKI;
            }
        }
        
        // Проверяем SearXNG
        foreach (self::SEARXNG->getDomains() as $domain) {
            if (str_contains($host, $domain)) {
                return self::SEARXNG;
            }
        }
        
        // Проверяем другие вики
        // Если домен содержит 'wiki' и это не Wikipedia/RuWiki/SearXNG
        if (str_contains($host, 'wiki')) {
            // Исключаем известные вики, которые уже обработаны выше
            if (!str_contains($host, 'wikipedia') && !str_contains($host, 'ruwiki')) {
                return self::OTHER_WIKI;
            }
        }
        
        // По умолчанию считаем generic
        return self::GENERIC;
    }
    
    /**
     * Извлекает язык Wikipedia из URL.
     * Например, из "en.wikipedia.org" извлекает "en".
     *
     * @param string $url URL Wikipedia
     * @return string|null Код языка или null, если не удалось извлечь
     */
    public static function extractWikipediaLanguage(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        
        if (!$host) {
            return null;
        }
        
        $hostParts = explode('.', $host);
        
        // Проверяем, является ли первый компонент языковым кодом (2-3 символа)
        if (count($hostParts) >= 3 && preg_match('/^[a-z]{2,3}$/i', $hostParts[0])) {
            return strtolower($hostParts[0]);
        }
        
        return 'en'; // По умолчанию английский
    }
    
    /**
     * Возвращает рекомендуемый цвет для отображения типа источника в UI.
     * Полезно для визуального различения типов источников.
     *
     * @return string Цвет в формате HEX
     */
    public function getColor(): string
    {
        return match($this) {
            self::WIKIPEDIA => '#3366CC', // Синий Wikipedia
            self::RUWIKI => '#CC3333',    // Красный RuWiki
            self::GENERIC => '#888888',   // Серый для обычных сайтов
            self::SEARXNG => '#4CAF50',   // Зеленый SearXNG
            self::OTHER_WIKI => '#FF9800', // Оранжевый для других вики
        };
    }
    
    /**
     * Возвращает иконку для типа источника (можно использовать в UI).
     *
     * @return string Название иконки или emoji
     */
    public function getIcon(): string
    {
        return match($this) {
            self::WIKIPEDIA => '🌐', // Глобус для Wikipedia
            self::RUWIKI => '🇷🇺',   // Флаг России для RuWiki
            self::GENERIC => '🔗',   // Ссылка для обычных сайтов
            self::SEARXNG => '🔍',   // Лупа для поисковой системы
            self::OTHER_WIKI => '📚', // Книга для других вики
        };
    }
    
    /**
     * Возвращает все доступные типы источников как массив.
     * Удобно для использования в формах, валидации и т.д.
     *
     * @return array Массив типов [значение => метка]
     */
    public static function getChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->getLabel();
        }
        return $choices;
    }
    
    /**
     * Возвращает только типы источников, которые являются вики-энциклопедиями.
     *
     * @return array Массив вики-типов [значение => метка]
     */
    public static function getWikiChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            if ($case->isWiki()) {
                $choices[$case->value] = $case->getLabel();
            }
        }
        return $choices;
    }
    
    /**
     * Возвращает только типы источников с исходным контентом.
     *
     * @return array Массив типов с исходным контентом [значение => метка]
     */
    public static function getOriginalContentChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            if ($case->isOriginalContent()) {
                $choices[$case->value] = $case->getLabel();
            }
        }
        return $choices;
    }
    
    /**
     * Проверяет, существует ли тип источника с указанным значением.
     *
     * @param string $value Значение для проверки
     * @return bool True, если тип существует
     */
    public static function isValid(string $value): bool
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Создает экземпляр перечисления из строки с безопасной обработкой.
     * В случае некорректного значения возвращает GENERIC.
     *
     * @param string $value Строковое значение
     * @return self Экземпляр перечисления или GENERIC по умолчанию
     */
    public static function tryFromSafe(string $value): self
    {
        return self::tryFrom($value) ?? self::GENERIC;
    }
}
