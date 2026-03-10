<?php

// src/app/modules/neuron/traits/tools/wiki/CoordinateExtractorTrait.php

namespace app\modules\neuron\traits\tools\wiki;

/**
 * Трейт для извлечения географических координат из текста.
 */
trait CoordinateExtractorTrait
{
    /**
     * Извлекает координаты из текста.
     * Ищет географические координаты в различных форматах.
     *
     * @param string $text Текст для анализа
     *
     * @return array Массив найденных координат
     */
    protected function extractCoordinates(string $text): array
    {
        $coordinates = [];

        // Паттерны для поиска координат
        $patterns = [
            // Формат: 40°26′46″ с. ш. 79°58′56″ з. д.
            '/(\d{1,3})°\s*(\d{1,2})′\s*(\d{1,2}(?:[,.]\d+)?)″\s*([сС]\.\s*[шШ]\.|[юЮ]\.\s*[шШ]\.|[nN]|[sS])\s*(\d{1,3})°\s*(\d{1,2})′\s*(\d{1,2}(?:[,.]\d+)?)″\s*([вВ]\.\s*[дД]\.|[зЗ]\.\s*[дД]\.|[eE]|[wW])/u',

            // Формат: 40°26′46″N 79°58′56″W
            '/(\d{1,3})°\s*(\d{1,2})′\s*(\d{1,2}(?:[,.]\d+)?)″\s*([NSns])\s*(\d{1,3})°\s*(\d{1,2})′\s*(\d{1,2}(?:[,.]\d+)?)″\s*([EWew])/u',

            // Формат: 40.446111, -79.982222 (десятичные градусы)
            '/([-+]?\d{1,3}\.\d+)\s*[,;]\s*([-+]?\d{1,3}\.\d+)/',

            // Формат: 40°26.767′N 79°58.933′W (градусы и десятичные минуты)
            '/(\d{1,3})°\s*(\d{1,2}(?:\.\d+)?)′\s*([NSns])\s*(\d{1,3})°\s*(\d{1,2}(?:\.\d+)?)′\s*([EWew])/u',

            // Формат: 40°26′N 79°58′W (только градусы и минуты)
            '/(\d{1,3})°\s*(\d{1,2})′\s*([NSns])\s*(\d{1,3})°\s*(\d{1,2})′\s*([EWew])/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $coord = $this->parseCoordinateMatch($match);
                    if ($coord !== null) {
                        $coordinates[] = $coord;
                    }
                }
            }
        }

        return $coordinates;
    }

    /**
     * Парсит найденное совпадение координат.
     *
     * @param array $match Результат preg_match
     *
     * @return array|null Массив с координатами [широта, долгота] или null
     */
    protected function parseCoordinateMatch(array $match): ?array
    {
        // Определяем формат совпадения по количеству элементов
        $count = count($match);

        if ($count === 9) {
            // Формат: 40°26′46″ с. ш. 79°58′56″ з. д.
            $latDeg = (float) $match[1];
            $latMin = (float) str_replace(',', '.', $match[2]);
            $latSec = (float) str_replace(',', '.', $match[3]);
            $latDir = $match[4];
            $lonDeg = (float) $match[5];
            $lonMin = (float) str_replace(',', '.', $match[6]);
            $lonSec = (float) str_replace(',', '.', $match[7]);
            $lonDir = $match[8];

            $lat = $latDeg + $latMin / 60 + $latSec / 3600;
            $lon = $lonDeg + $lonMin / 60 + $lonSec / 3600;

            // Применяем направление
            if (preg_match('/[юЮsS]|[сС]\.\s*[шШ]\./', $latDir)) {
                $lat = -$lat;
            }

            if (preg_match('/[зЗwW]|[зЗ]\.\s*[дД]\./', $lonDir)) {
                $lon = -$lon;
            }

            return [$lat, $lon];
        }

        if ($count === 6) {
            // Формат: 40.446111, -79.982222
            $lat = (float) str_replace(',', '.', $match[1]);
            $lon = (float) str_replace(',', '.', $match[2]);

            return [$lat, $lon];
        }

        if ($count === 8) {
            // Формат: 40°26′46″N 79°58′56″W
            $latDeg = (float) $match[1];
            $latMin = (float) str_replace(',', '.', $match[2]);
            $latSec = (float) str_replace(',', '.', $match[3]);
            $latDir = $match[4];
            $lonDeg = (float) $match[5];
            $lonMin = (float) str_replace(',', '.', $match[6]);
            $lonSec = (float) str_replace(',', '.', $match[7]);
            $lonDir = $match[8];

            $lat = $latDeg + $latMin / 60 + $latSec / 3600;
            $lon = $lonDeg + $lonMin / 60 + $lonSec / 3600;

            if (strtoupper($latDir) === 'S') {
                $lat = -$lat;
            }
            if (strtoupper($lonDir) === 'W') {
                $lon = -$lon;
            }

            return [$lat, $lon];
        }

        if ($count === 7) {
            // Формат: 40°26.767′N 79°58.933′W
            $latDeg = (float) $match[1];
            $latMin = (float) str_replace(',', '.', $match[2]);
            $latDir = $match[3];
            $lonDeg = (float) $match[4];
            $lonMin = (float) str_replace(',', '.', $match[5]);
            $lonDir = $match[6];

            $lat = $latDeg + $latMin / 60;
            $lon = $lonDeg + $lonMin / 60;

            if (strtoupper($latDir) === 'S') {
                $lat = -$lat;
            }
            if (strtoupper($lonDir) === 'W') {
                $lon = -$lon;
            }

            return [$lat, $lon];
        }

        if ($count === 5) {
            // Формат: 40°26′N 79°58′W
            $latDeg = (float) $match[1];
            $latMin = (float) str_replace(',', '.', $match[2]);
            $latDir = $match[3];
            $lonDeg = (float) $match[4];
            $lonMin = (float) str_replace(',', '.', $match[5]);
            $lonDir = $match[6];

            $lat = $latDeg + $latMin / 60;
            $lon = $lonDeg + $lonMin / 60;

            if (strtoupper($latDir) === 'S') {
                $lat = -$lat;
            }
            if (strtoupper($lonDir) === 'W') {
                $lon = -$lon;
            }

            return [$lat, $lon];
        }

        return null;
    }
}
