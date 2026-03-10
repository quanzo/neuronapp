<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\params\ParamListDto;

/**
 * Вспомогательные методы для работы с плейсхолдерами вида $paramName.
 */
class PlaceholderHelper
{
    /**
     * Находит все плейсхолдеры вида $paramName в тексте и возвращает
     * уникальный список их имён без ведущего знака доллара.
     *
     * @return string[]
     */
    public static function collectPlaceholders(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $matches = [];
        preg_match_all('/\$([a-zA-Z]+)/', $text, $matches);

        if (empty($matches[1])) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Подставляет значения именованных параметров в текст.
     *
     * Каждый плейсхолдер $name заменяется строковым значением
     * из массива $params с ключом 'name'. Отсутствующие параметры
     * заменяются на пустую строку.
     *
     * @param array<string, mixed> $params
     */
    public static function renderWithParams(string $text, array $params = []): string
    {
        if ($text === '') {
            return '';
        }

        $placeholders = self::collectPlaceholders($text);

        if ($placeholders === []) {
            return $text;
        }

        $replacements = [];

        foreach ($placeholders as $placeholder) {
            $value = array_key_exists($placeholder, $params) ? (string) $params[$placeholder] : '';
            $replacements['$' . $placeholder] = $value;
        }

        return strtr($text, $replacements);
    }

    /**
     * Проверяет соответствие описания params списку плейсхолдеров.
     *
     * @param ParamListDto|null $paramList     Описание параметров (может быть null при ошибке парсинга).
     * @param string[]          $placeholders  Имена плейсхолдеров без '$'.
     *
     * @return array<int, array{type:string, message:string, param?:string}>
     */
    public static function validateParamList(?ParamListDto $paramList, array $placeholders): array
    {
        $errors = [];
        $definedParams = $paramList?->all() ?? [];

        foreach ($placeholders as $placeholder) {
            if ($paramList === null || !$paramList->has($placeholder)) {
                $errors[] = [
                    'type' => 'missing_param_definition',
                    'param' => $placeholder,
                    'message' => "Параметр \${$placeholder} используется в тексте, но не описан в опции params.",
                ];
            }
        }

        foreach ($definedParams as $defined) {
            $name = $defined->getName();
            if (!in_array($name, $placeholders, true)) {
                $errors[] = [
                    'type' => 'unused_param_definition',
                    'param' => $name,
                    'message' => "Параметр {$name} описан в опции params, но не используется в тексте.",
                ];
            }
        }

        return $errors;
    }
}
