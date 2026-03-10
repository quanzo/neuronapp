<?php

// src/app/modules/neuron/classes/loader/web/GenericWebLoader.php

namespace app\modules\neuron\classes\loader\web;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\convert\Mdify;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;
use app\modules\neuron\enums\ContentSourceType;
use app\modules\neuron\interfaces\ContentLoaderInterface;
use Html2Text\Html2Text;

/**
 * Универсальный загрузчик для произвольных веб-страниц.
 * Загружает HTML-контент любых сайтов, которые не обрабатываются
 * специализированными загрузчиками (WikipediaLoader, RuWikiLoader).
 *
 * Внимание: Этот загрузчик должен быть последним в цепочке загрузчиков,
 * так как он всегда возвращает true в методе canLoad().
 */
class GenericWebLoader implements ContentLoaderInterface
{
    /**
     * HTTP-клиент Amp для выполнения запросов
     * @var HttpClient
     */
    protected HttpClient $httpClient;

    /**
     * Конструктор универсального загрузчика.
     */
    public function __construct()
    {
        $this->httpClient = HttpClientBuilder::buildDefault();
    }

    /**
     * Проверяет, может ли загрузчик обработать URL.
     * GenericWebLoader всегда возвращает true, так как он является
     * загрузчиком по умолчанию для любых URL, которые не были обработаны
     * другими загрузчиками.
     *
     * Внимание: Этот метод должен использоваться менеджером загрузчиков
     * для определения того, что данный URL не может быть обработан
     * специализированными загрузчиками.
     *
     * @param string $url URL для проверки
     * @return bool Всегда true
     */
    public function canLoad(string $url): bool
    {
        // GenericWebLoader всегда может загрузить URL,
        // так как он является загрузчиком последней инстанции
        return true;
    }

    /**
     * Загружает содержимое произвольной веб-страницы.
     * Выполняет HTTP-запрос и извлекает контент из HTML.
     *
     * @throws \InvalidArgumentException Если URL пустой или невалидный
     *
     * @param string $url URL веб-страницы
     * @return Future<ArticleContentDto> Future с содержимым страницы
     */
    public function load(string $url): Future
    {
        return \Amp\async(function () use ($url) {
            // Проверяем, что URL не пустой
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException(
                    "Некорректный URL: {$url}"
                );
            }

            try {
                // Выполняем HTTP-запрос
                $html = $this->fetchHtmlContent($url);

                // Извлекаем заголовок из HTML
                $title = $this->extractTitleFromHtml($html);

                // Очищаем HTML (убираем скрипты, стили и т.д.)
                $cleanedHtml = $this->cleanHtml($html);

                /*
                $html = new Html2Text($cleanedHtml, [
                    'width' => 0,
                ]);
                $content = $html->getText();
                */
                $content = Mdify::htmlToMarkdown($cleanedHtml);

                // Используем enum для типа источника
                return new ArticleContentDto(
                    content   : $content,
                    title     : $title,
                    sourceUrl : $url,
                    sourceType: ContentSourceType::GENERIC,
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Не удалось загрузить страницу {$url}: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        });
    }

    /**
     * Выполняет HTTP-запрос и возвращает HTML-контент.
     *
     * @param string $url URL для загрузки
     * @return string HTML содержимое страницы
     */
    protected function fetchHtmlContent(string $url): string
    {
        $request = new Request($url, 'GET');

        // Устанавливаем разумные заголовки для имитации браузера
        $request->setHeaders([
            'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language'           => 'ru-Ru,ru,en-US,en;q=0.9',
            'Accept-Encoding'           => 'gzip, deflate',
            'Connection'                => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
        ]);

        $response = $this->httpClient->request($request);

        // Проверяем успешность запроса
        if ($response->getStatus() !== 200) {
            throw new \RuntimeException(
                "HTTP ошибка: статус " . $response->getStatus() . " для URL: {$url}"
            );
        }

        $content = $response->getBody()->buffer();
        $arHeaders = $response->getHeaders();

        if (!empty($arHeaders['content-encoding'])) {
            switch (true) {
                case in_array('gzip', $arHeaders['content-encoding']):
                    $content = gzdecode($content);
                    break;
                case in_array('deflate', $arHeaders['content-encoding']):
                    $content = gzinflate($content);
                    break;
            }
        }

        return $content;
    }

    /**
     * Извлекает заголовок из HTML документа.
     *
     * @param string $html HTML содержимое страницы
     * @return string Заголовок страницы
     */
    protected function extractTitleFromHtml(string $html): string
    {
        // Ищем заголовок в теге <title>
        if (preg_match('/<title[^>]*>\s*(.*?)\s*<\/title>/is', $html, $matches)) {
            $title = trim(html_entity_decode(strip_tags($matches[1])));
            if (!empty($title)) {
                return $title;
            }
        }

        // Ищем заголовок в теге <h1>
        if (preg_match('/<h1[^>]*>\s*(.*?)\s*<\/h1>/is', $html, $matches)) {
            $title = trim(html_entity_decode(strip_tags($matches[1])));
            if (!empty($title)) {
                return $title;
            }
        }

        // Ищем заголовок в Open Graph meta тегах
        if (preg_match('/<meta[^>]*property="og:title"[^>]*content="([^"]*)"[^>]*>/i', $html, $matches)) {
            $title = trim(html_entity_decode(strip_tags($matches[1])));
            if (!empty($title)) {
                return $title;
            }
        }

        // Ищем заголовок в Twitter meta тегах
        if (preg_match('/<meta[^>]*name="twitter:title"[^>]*content="([^"]*)"[^>]*>/i', $html, $matches)) {
            $title = trim(html_entity_decode(strip_tags($matches[1])));
            if (!empty($title)) {
                return $title;
            }
        }

        return 'Без названия';
    }

    /**
     * Очищает HTML, удаляя ненужные элементы.
     *
     * @param string $html Исходный HTML
     * @return string Очищенный HTML
     */
    protected function cleanHtml(string $html): string
    {
        // Удаляем скрипты и стили
        $cleaned = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $cleaned = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $cleaned);
        $cleaned = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $cleaned);

        // Удаляем комментарии
        $cleaned = preg_replace('/<!--.*?-->/s', '', $cleaned);

        // Удаляем пустые строки и лишние пробелы
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);

        return $cleaned;
    }
}
