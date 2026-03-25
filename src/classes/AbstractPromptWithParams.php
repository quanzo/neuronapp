<?php

declare(strict_types=1);

namespace app\modules\neuron\classes;

use app\modules\neuron\classes\dto\params\ParamListDto;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\helpers\OptionsHelper;
use app\modules\neuron\helpers\PlaceholderHelper;
use app\modules\neuron\interfaces\IDependConfigApp;
use app\modules\neuron\traits\DependConfigAppTrait;
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
abstract class AbstractPromptWithParams extends APromptComponent implements IDependConfigApp
{
    use DependConfigAppTrait;

    protected string $name;

    /**
     * Конфигурация агента по умолчанию, которая может быть переопределена опцией "agent".
     */
    private ?ConfigurationAgent $defaultAgentCfg = null;

    private ?ParamListDto $paramListCache = null;
    private bool $paramListCacheInitialized = false;

    /**
     * Базовый конструктор элементов
     *
     * @param string                $input содержимое
     * @param string                $name название (чаще всего будет имя файла)
     * @param ConfigurationApp|null $configApp конфигурация приложения ибо там можно получить связанные элементы
     */
    public function __construct(string $input, string $name = '', ?ConfigurationApp $configApp = null)
    {
        parent::__construct($input);
        $this->body = CommentsHelper::stripComments($this->body);
        $this->name = $name;
        $this->setConfigurationApp($configApp);
    }

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

        /**
         * !Важно! Skill и TodoList могут настраивать агент по своим настройкам. Если объект агента будет один для всех, то настройки будут повторяться и накапливаться между сессиями.
         */
        return clone $agentCfg;
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
     * Формирует итоговый набор значений параметров с учётом описания params.
     *
     * Приоритет значений:
     *  1) $runtimeParams — значения, переданные при непосредственном вызове компонента;
     *  2) $sessionParams — значения, переданные извне (например, из CLI/конфига сессии);
     *  3) default из описания параметров (опция params).
     *
     * Имена параметров должны соответствовать [a-zA-Z]+, как и плейсхолдеры
     * в тексте компонента (например, $date, $branch, $user).
     *
     * @param array<string,mixed>|null $runtimeParams  Значения, переданные при вызове компонента.
     * @param array<string,mixed>|null $sessionParams  Сессионные значения (дата, ветка, пользователь и др.).
     *
     * @return array<string,mixed> Итоговый набор значений параметров.
     */
    protected function buildEffectiveParams(?array $runtimeParams, ?array $sessionParams = null): array
    {
        $effective = [];

        $paramList = $this->getParamList();
        if ($paramList !== null) {
            foreach ($paramList->all() as $param) {
                $default = $param->getDefault();
                if ($default !== null) {
                    $effective[$param->getName()] = $default;
                }
            }
        }

        if ($sessionParams !== null) {
            foreach ($sessionParams as $name => $value) {
                $effective[(string) $name] = $value;
            }
        }

        if ($runtimeParams !== null) {
            foreach ($runtimeParams as $name => $value) {
                $effective[(string) $name] = $value;
            }
        }

        return $effective;
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
     * Разбирает строку tools в список имён инструментов.
     *
     * @return list<string>
     */
    protected function parseTools(): array
    {
        $options = $this->getOptions();
        $toolsOpt = $options['tools'] ?? '';

        if (!is_string($toolsOpt)) {
            return [];
        }

        $names = array_map('trim', explode(',', $toolsOpt));

        return array_values(array_filter($names, static fn(string $s): bool => $s !== ''));
    }

    /**
     * Возвращает имена встроенных инструментов, которые нужно подключить.
     *
     * Источник списка — опция "tools" в блоке настроек компонента.
     * Нестроковые значения опции считаются некорректными и приводят
     * к пустому результату.
     *
     * @return list<string> Список имён инструментов.
     */
    public function getNeedTools(): array
    {
        return $this->parseTools();
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
     * Валидирует опцию tools.
     *
     * @return array<int, array{type:string, message:string}>
     */
    protected function validateToolsOption(): array
    {
        $errors = [];
        $options = $this->getOptions();

        if (!array_key_exists('tools', $options)) {
            return $errors;
        }

        $toolsOpt = $options['tools'];
        if (!is_string($toolsOpt)) {
            $errors[] = [
                'type' => 'invalid_tools_option_type',
                'message' => 'Опция tools должна быть строкой с именами инструментов через запятую.',
            ];

            return $errors;
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
            $this->validateToolsOption(),
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
