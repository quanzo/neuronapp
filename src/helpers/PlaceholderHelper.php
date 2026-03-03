<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

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
     * Проверяет соответствие опции params списку плейсхолдеров.
     *
     * @param mixed                $optionsParams  Значение опции params (ожидается массив).
     * @param string[]             $placeholders   Имена плейсхолдеров без '$'.
     * @return array<int, array{type:string, message:string, param?:string}>
     */
    public static function validateParams(mixed $optionsParams, array $placeholders): array
    {
        $errors = [];
        $definedParams = [];

        if ($optionsParams !== null && !is_array($optionsParams)) {
            $errors[] = [
                'type' => 'invalid_params_type',
                'message' => 'Опция params должна быть JSON-объектом (массивом) с описаниями параметров.',
            ];

            return $errors;
        }

        if (is_array($optionsParams)) {
            foreach ($optionsParams as $paramName => $def) {
                if (!is_string($paramName) || preg_match('/^[a-zA-Z]+$/', $paramName) !== 1) {
                    $errors[] = [
                        'type' => 'invalid_param_name',
                        'param' => (string) $paramName,
                        'message' => 'Имя параметра должно содержать только латинские буквы [a-zA-Z].',
                    ];
                    continue;
                }

                $definedParams[] = $paramName;

                if (!is_string($def) && !is_array($def)) {
                    $errors[] = [
                        'type' => 'invalid_param_definition_type',
                        'param' => $paramName,
                        'message' => 'Описание параметра должно быть строкой (тип) или массивом с ключом type.',
                    ];
                    continue;
                }

                if (is_array($def) && isset($def['type']) && !is_string($def['type'])) {
                    $errors[] = [
                        'type' => 'invalid_param_type_value',
                        'param' => $paramName,
                        'message' => 'Поле type в описании параметра должно быть строкой.',
                    ];
                }
            }
        }

        foreach ($placeholders as $placeholder) {
            if (!in_array($placeholder, $definedParams, true)) {
                $errors[] = [
                    'type' => 'missing_param_definition',
                    'param' => $placeholder,
                    'message' => "Параметр \${$placeholder} используется в тексте, но не описан в опции params.",
                ];
            }
        }

        foreach ($definedParams as $defined) {
            if (!in_array($defined, $placeholders, true)) {
                $errors[] = [
                    'type' => 'unused_param_definition',
                    'param' => $defined,
                    'message' => "Параметр {$defined} описан в опции params, но не используется в тексте.",
                ];
            }
        }

        return $errors;
    }
}

