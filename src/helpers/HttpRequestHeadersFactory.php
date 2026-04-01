<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\tools\HttpFetchRequestHeadersDto;

/**
 * Фабрика для построения исходящих HTTP-заголовков инструментов.
 *
 * Зачем нужна:
 * - в проекте несколько инструментов отправляют HTTP-запросы и используют одинаковую базу
 *   заголовков «в стиле Firefox» (см. {@see HttpFetchRequestHeadersDto::firefoxDefaults()});
 * - чтобы избежать дублирования "firefoxDefaults()->merge(...)" в каждом инструменте.
 *
 * Пример:
 *
 * <code>
 * $headers = HttpRequestHeadersFactory::firefoxMerged(
 *     HttpFetchRequestHeadersDto::empty()->withHeader('Authorization', 'Bearer ...')
 * );
 * </code>
 */
final class HttpRequestHeadersFactory
{
    /**
     * Возвращает заголовки Firefox по умолчанию, сливая (опционально) пользовательские.
     *
     * Заголовки из `$custom` перекрывают совпадающие имена (без учёта регистра).
     *
     * @param HttpFetchRequestHeadersDto|null $custom Пользовательские заголовки (опционально)
     *
     * @return HttpFetchRequestHeadersDto Итоговый набор заголовков
     */
    public static function firefoxMerged(?HttpFetchRequestHeadersDto $custom = null): HttpFetchRequestHeadersDto
    {
        return $custom === null
            ? HttpFetchRequestHeadersDto::firefoxDefaults()
            : HttpFetchRequestHeadersDto::firefoxDefaults()->merge($custom);
    }
}
