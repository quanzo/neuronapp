<?php

declare(strict_types=1);

namespace app\modules\neuron\classes;

use app\modules\neuron\classes\dto\params\ParamListDto;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\helpers\OptionsHelper;
use app\modules\neuron\helpers\PlaceholderHelper;
use RuntimeException;

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
    protected string $name;

    /**
     * Глобальная конфигурация приложения, используемая для разрешения агентов и зависимостей.
     */
    private ?ConfigurationApp $configApp = null;

    /**
     * Конфигурация агента по умолчанию, которая может быть переопределена опцией "agent".
     */
    private ?ConfigurationAgent $defaultAgentCfg = null;

    private ?ParamListDto $paramListCache = null;
    private bool $paramListCacheInitialized = false;

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Имя компонента, используемое, например, для проверки самоссылок
     */
    protected function getComponentName(): string
    {
        return $this->getName();
    }

    /**
     * Устанавливает конфигурацию приложения для компонента.
     *
     * @param ConfigurationApp|null $configApp Экземпляр конфигурации приложения или null.
     *
     * @return static
     */
    public function setConfigurationApp(?ConfigurationApp $configApp): static
    {
        $this->configApp = $configApp;

        return $this;
    }

    /**
     * Возвращает конфигурацию приложения, если она была установлена.
     */
    public function getConfigurationApp(): ?ConfigurationApp
    {
        return $this->configApp;
    }

    /**
     * Устанавливает конфигурацию агента по умолчанию для компонента.
     *
     * @return static
     */
    public function setDefaultConfigurationAgent(?ConfigurationAgent $agentCfg): static
    {
        $this->defaultAgentCfg = $agentCfg;

        return $this;
    }

    /**
     * Возвращает конфигурацию агента для выполнения компонента.
     *
     * Если в опциях компонента задано имя агента (опция "agent"), то агент берётся
     * по этому имени через ConfigurationApp. В противном случае возвращается
     * агент, переданный в аргументе метода.
     *
     * @param ConfigurationAgent|null $agentCfg Агент по умолчанию, если имя агента в опциях не задано.
     *
     * @return ConfigurationAgent Конфигурация агента для исполнения компонента.
     *
     * @throws RuntimeException Если имя агента задано, но ConfigurationApp не установлен
     *                          или агент с таким именем не найден, либо не передан агент по умолчанию.
     */
    public function getConfigurationAgent(?ConfigurationAgent $agentCfg = null): ConfigurationAgent
    {
        if ($agentCfg === null) {
            $agentCfg = $this->defaultAgentCfg;
        }

        $agentName = $this->getAgentName();

        if ($agentName !== null) {
            $configApp = $this->getConfigurationApp();
            if ($configApp === null) {
                throw new RuntimeException(
                    sprintf(
                        'ConfigurationApp не установлен для компонента "%s", не удалось разрешить агента "%s".',
                        $this->getComponentName(),
                        $agentName
                    )
                );
            }

            $resolved = $configApp->getAgent($agentName);
            if ($resolved === null) {
                throw new RuntimeException(
                    sprintf(
                        'Агент "%s" не найден в ConfigurationApp для компонента "%s".',
                        $agentName,
                        $this->getComponentName()
                    )
                );
            }

            $agentCfg = $resolved;
        }

        if ($agentCfg === null) {
            throw new RuntimeException(
                sprintf(
                    'Не удалось определить конфигурацию агента для компонента "%s": имя агента не задано и агент по умолчанию не передан.',
                    $this->getComponentName()
                )
            );
        }

        return $agentCfg;
    }

    /**
     * Описание компонента (если есть)
     *
     * @return string|null
     */
    public function getDescription(): ?string
    {
        $options = $this->getOptions();

        return $options['description'] ?? null;
    }

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
     * Определяет, нужно ли выполнять навык с чистым контекстом.
     *
     * Чистый контекст — использование клона конфигурации агента (cloneForSession),
     * чтобы не изменять основное состояние агента (историю, кеш).
     * Опция задаётся параметром "pure_context" в блоке опций навыка.
     *
     * По умолчанию значение берётся из {@see getDefaultPureContext()}, но
     * может быть переопределено опцией "pure_context" в блоке настроек.
     *
     * @return bool true, если опция pure_context задана как 1 или 'true';
     *              false — если не задана и значение по умолчанию ложно,
     *              либо опция задана как 0 или 'false'.
     */
    public function isPureContext(): bool
    {
        $options = $this->getOptions();
        $value = $options['pure_context'] ?? $this->getDefaultPureContext();

        return OptionsHelper::toBool($value);
    }

    /**
     * Значение по умолчанию для опции pure_context.
     *
     * Наследники могут переопределять этот метод, чтобы задать
     * собственное значение по умолчанию для использования чистого
     * контекста при выполнении компонента.
     */
    protected function getDefaultPureContext(): bool
    {
        return true;
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

    /**
     * Проверяет корректность конфигурации компонента и возвращает список ошибок.
     * 
     * Формат каждой ошибки:
     *  - type: строковый код ошибки;
     *  - message: человекочитаемое описание;
     *  - param: опционально, имя параметра, к которому относится ошибка.
     *
     * @return array<int, array{type:string, message:string, param?:string}>
     */
    public function checkErrors(): array
    {
        return $this->getErrors();
    }
}
