<?php
// src/app/modules/neuron/interfaces/wiki/search/ArticleSearcherInterface.php

namespace app\modules\neuron\interfaces;

use Amp\Future;
use app\modules\neuron\classes\dto\wiki\ArticleContentDto;

/**
 * Интерфейс для поиска статей в вики-энциклопедиях.
 * Определяет метод для асинхронного поиска статей по запросу.
 */
interface ArticleSearcherInterface
{
    /**
     * Выполняет поиск статей по указанному запросу.
     * Возвращает массив ArticleContentDto с полным содержимым найденных статей.
     *
     * @param string $query Поисковый запрос
     * @param int $limit Максимальное количество результатов (по умолчанию 10)
     * @param int $offset Смещение для пагинации (по умолчанию 0)
     * @return Future<ArticleContentDto[]> Future с массивом DTO найденных статей
     */
    public function search(string $query, int $limit = 10, int $offset = 0): Future;
}

