<?php
// src/app/modules/neuron/classes/tools/wiki/ContentLoaderInterface.php

namespace app\modules\neuron\classes\tools\wiki;

use Amp\Future;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;

/**
 * Интерфейс для загрузки содержимого статей из различных источников.
 * Каждый загрузчик сам определяет, может ли он обработать переданный URL.
 * 
 * Реализующие классы должны гарантировать, что метод load() вызывается
 * только для URL, прошедших проверку canLoad().
 */
interface ContentLoaderInterface
{
    /**
     * Проверяет, может ли данный загрузчик обработать указанный URL.
     * Загрузчик анализирует URL и возвращает true, если URL соответствует
     * его области ответственности (например, WikipediaLoader проверяет
     * наличие "wikipedia.org" в домене).
     * 
     * Этот метод должен быть вызван перед методом load().
     *
     * @param string $url URL для проверки
     * @return bool True, если загрузчик может обработать этот URL
     */
    public function canLoad(string $url): bool;

    /**
     * Загружает содержимое статьи по указанному URL.
     * Вызывается только для URL, которые прошли проверку canLoad().
     * 
     * Реализующий класс ДОЛЖЕН гарантировать, что метод вызывается
     * только для URL, которые он может обработать. Если метод вызывается
     * для неподдерживаемого URL, должно быть выброшено исключение.
     *
     * @param string $url URL статьи или веб-страницы
     * @return Future<ArticleContentDto> Future, которое разрешится в DTO содержимого
     * @throws \InvalidArgumentException Если передан URL, который не может быть обработан этим загрузчиком
     * @throws \RuntimeException Если не удалось загрузить контент
     */
    public function load(string $url): Future;
}
