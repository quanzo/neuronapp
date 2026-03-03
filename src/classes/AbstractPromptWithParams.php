<?php

declare(strict_types=1);

namespace app\modules\neuron\classes;

/**
 * Базовый компонент для промптов с параметрами и опцией skills.
 *
 * Содержит общую логику:
 *  - поиска плейсхолдеров вида $paramName в теле;
 *  - валидации опции params;
 *  - валидации и разборки опции skills;
 *  - агрегирования ошибок конфигурации.
 */
abstract class AbstractPromptWithParams extends APromptComponent
{
    /**
     * Имя компонента, используемое, например, для проверки самоссылок в skills.
     */
    abstract protected function getComponentName(): string;

    /**
     * Собирает список плейсхолдеров вида $paramName в теле компонента.
     *
     * @return string[]
     */
    protected function collectPlaceholdersFromBody(): array
    {
        $body = $this->getBody();

        if ($body === '') {
            return [];
        }

        $matches = [];
        preg_match_all('/\$([a-zA-Z]+)/', $body, $matches);

        if (empty($matches[1])) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Проверяет опцию params с учётом списка используемых плейсхолдеров.
     *
     * @param string[] $placeholders
     * @return array<int, array{type:string, message:string, param?:string}>
     */
    protected function validateParamsOption(array $placeholders): array
    {
        $errors = [];
        $options = $this->getOptions();

        $paramsOption = $options['params'] ?? null;
        $definedParams = [];

        if ($paramsOption !== null && !is_array($paramsOption)) {
            $errors[] = [
                'type' => 'invalid_params_type',
                'message' => 'Опция params должна быть JSON-объектом (массивом) с описаниями параметров.',
            ];
        } elseif (is_array($paramsOption)) {
            foreach ($paramsOption as $paramName => $def) {
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

    /**
     * Разбирает строку skills в список имён.
     *
     * @return list<string>
     */
    protected function parseSkills(bool $excludeSelf = true): array
    {
        $options = $this->getOptions();
        $skillsOpt = $options['skills'] ?? '';

        if (!is_string($skillsOpt)) {
            return [];
        }

        $names = array_map('trim', explode(',', $skillsOpt));
        $names = array_values(array_filter($names, static fn(string $s): bool => $s !== ''));

        if ($excludeSelf) {
            $self = $this->getComponentName();
            $names = array_values(array_filter($names, static fn(string $s) => $s !== $self));
        }

        return $names;
    }

    /**
     * Валидирует опцию skills, включая формат и самоссылки.
     *
     * @return array<int, array{type:string, message:string}>
     */
    protected function validateSkillsOption(): array
    {
        $errors = [];
        $options = $this->getOptions();

        if (!array_key_exists('skills', $options)) {
            return $errors;
        }

        $skillsOpt = $options['skills'];
        if (!is_string($skillsOpt)) {
            $errors[] = [
                'type' => 'invalid_skills_option_type',
                'message' => 'Опция skills должна быть строкой с именами навыков через запятую.',
            ];

            return $errors;
        }

        $names = array_map('trim', explode(',', $skillsOpt));
        $selfName = $this->getComponentName();

        foreach ($names as $name) {
            if ($name !== '' && $name === $selfName) {
                $errors[] = [
                    'type' => 'self_referenced_skill',
                    'message' => 'Опция skills содержит имя самого компонента, что может привести к рекурсивному вызову.',
                ];
                break;
            }
        }

        return $errors;
    }

    /**
     * Проверка на пустое тело. По умолчанию ничего не проверяет.
     *
     * @return array<int, array{type:string, message:string}>
     */
    protected function validateEmptyBody(): array
    {
        return [];
    }

    /**
     * Проверка params с учётом тела.
     *
     * @return array<int, array{type:string, message:string, param?:string}>
     */
    protected function validateParams(): array
    {
        $placeholders = $this->collectPlaceholdersFromBody();

        return $this->validateParamsOption($placeholders);
    }

    /**
     * Проверка skills.
     *
     * @return array<int, array{type:string, message:string}>
     */
    protected function validateSkills(): array
    {
        return $this->validateSkillsOption();
    }

    /**
     * Агрегирует все ошибки конфигурации компонента.
     *
     * @return array<int, array{type:string, message:string, param?:string}>
     */
    public function getErrors(): array
    {
        $errors = [];

        $errors = array_merge(
            $errors,
            $this->validateEmptyBody(),
            $this->validateParams(),
            $this->validateSkills(),
        );

        return $errors;
    }
}

