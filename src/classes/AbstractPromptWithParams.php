<?php

declare(strict_types=1);

namespace app\modules\neuron\classes;

use app\modules\neuron\classes\dto\params\ParamListDto;
use app\modules\neuron\helpers\PlaceholderHelper;

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
    private ?ParamListDto $paramListCache = null;
    private bool $paramListCacheInitialized = false;

    /**
     * Имя компонента, используемое, например, для проверки самоссылок в skills.
     */
    abstract protected function getComponentName(): string;

    /**
     * Возвращает список параметров (опция params) в виде DTO.
     *
     * Если опция отсутствует, возвращается пустой список параметров.
     * При некорректной опции (невалидный JSON/тип) возвращает null.
     */
    public function getParamList(): ?ParamListDto
    {
        if ($this->paramListCacheInitialized) {
            return $this->paramListCache;
        }

        $this->paramListCacheInitialized = true;

        $options = $this->getOptions();
        $paramsOption = $options['params'] ?? null;

        [$list, $errors] = ParamListDto::tryFromOptionValue($paramsOption);
        if ($errors !== []) {
            $this->paramListCache = null;
            return null;
        }

        $this->paramListCache = $list;
        return $this->paramListCache;
    }

    /**
     * Собирает список плейсхолдеров вида $paramName в теле компонента.
     *
     * @return string[]
     */
    protected function collectPlaceholdersFromBody(): array
    {
        return PlaceholderHelper::collectPlaceholders($this->getBody());
    }

    /**
     * Проверяет опцию params с учётом списка используемых плейсхолдеров.
     *
     * @param string[] $placeholders
     * @return array<int, array{type:string, message:string, param?:string}>
     */
    protected function validateParamsOption(array $placeholders): array
    {
        $options = $this->getOptions();
        $paramsOption = $options['params'] ?? null;
        [$paramList, $parseErrors] = ParamListDto::tryFromOptionValue($paramsOption);

        return array_merge(
            $parseErrors,
            PlaceholderHelper::validateParamList($paramList, $placeholders),
        );
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

