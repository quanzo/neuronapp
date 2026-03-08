<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\helpers\PlaceholderHelper;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * Инструмент выполнения предопределённой shell-команды с параметризацией.
 *
 * В отличие от {@see BashTool}, который позволяет LLM передавать произвольную
 * shell-команду, BashCmdTool работает с заранее заданным шаблоном команды.
 * Шаблон содержит плейсхолдеры вида `$paramName` (формат идентичен
 * {@see \app\modules\neuron\classes\skill\Skill} и
 * {@see \app\modules\neuron\classes\todo\TodoList}), которые подставляются
 * из значений параметров, переданных LLM.
 *
 * Правила работы с плейсхолдерами:
 * - Имена плейсхолдеров содержат только латинские буквы [a-zA-Z]+.
 * - Подстановка выполняется через {@see PlaceholderHelper::renderWithParams()}.
 * - Плейсхолдер, для которого LLM не передал значение, заменяется на пустую строку.
 *
 * Пример использования:
 * ```php
 * $tool = new BashCmdTool(
 *     commandTemplate: 'git log --oneline -$count $branch',
 *     name: 'git_log',
 *     description: 'Показывает последние коммиты указанной ветки.',
 * );
 * $tool->addProperty(new ToolProperty('count', PropertyType::INTEGER, 'Количество коммитов', true));
 * $tool->addProperty(new ToolProperty('branch', PropertyType::STRING, 'Имя ветки', true));
 * ```
 *
 * При вызове LLM передаёт `{count: 10, branch: "main"}`, инструмент формирует
 * команду `git log --oneline -10 main` и исполняет её.
 *
 * Выполнение команды делегируется внутреннему экземпляру {@see BashTool}, который
 * обеспечивает управление таймаутами, ограничение вывода, белый/чёрный списки
 * команд и переменные окружения. Все настройки выполнения доступны через сеттеры.
 *
 * Результат возвращается в виде JSON через {@see \app\modules\neuron\classes\dto\tools\BashResultDto}.
 *
 * @see PlaceholderHelper  Механизм подстановки плейсхолдеров
 * @see BashTool           Исполнитель shell-команд (делегат)
 */
class BashCmdTool extends ATool
{
    /**
     * Шаблон bash-команды с плейсхолдерами вида $paramName.
     *
     * Плейсхолдеры заменяются значениями параметров, переданными LLM.
     * Формат: только латинские буквы [a-zA-Z]+.
     */
    protected string $commandTemplate;

    /**
     * Внутренний исполнитель shell-команд.
     *
     * Инкапсулирует всю логику запуска процесса, мониторинга таймаутов,
     * чтения stdout/stderr и валидации по белым/чёрным спискам.
     */
    protected BashTool $executor;

    /**
     * Создаёт инструмент для выполнения предопределённой bash-команды.
     *
     * Шаблон команды задаётся один раз при создании инструмента.
     * Параметры инструмента (LLM-свойства) добавляются после создания
     * через {@see Tool::addProperty()} и должны соответствовать плейсхолдерам
     * в шаблоне команды.
     *
     * Настройки выполнения (таймаут, ограничения вывода, списки доступа)
     * передаются внутреннему {@see BashTool} через конструктор или сеттеры.
     *
     * @param string               $commandTemplate  Шаблон bash-команды с плейсхолдерами $paramName
     * @param string               $name             Имя инструмента для LLM (по умолчанию 'bash_cmd')
     * @param string               $description      Описание инструмента для LLM
     * @param int                  $defaultTimeout   Таймаут выполнения команды (секунды, по умолчанию 30)
     * @param int                  $maxOutputSize    Максимальный размер stdout/stderr (байт, по умолчанию 100 КБ)
     * @param string               $workingDirectory Рабочая директория (пустая строка — текущая директория)
     * @param string[]             $allowedPatterns  Regex-шаблоны разрешённых команд (пустой — все разрешены)
     * @param string[]             $blockedPatterns  Regex-шаблоны запрещённых команд
     * @param array<string,string> $env              Дополнительные переменные окружения
     */
    public function __construct(
        string $commandTemplate,
        string $name             = 'bash_cmd',
        string $description      = 'Выполнение предопределённой shell-команды с параметрами.',
        int    $defaultTimeout   = 30,
        int    $maxOutputSize    = 102400,
        string $workingDirectory = '',
        array  $allowedPatterns  = [],
        array  $blockedPatterns  = [],
        array  $env              = [],
    ) {
        parent::__construct(name: $name, description: $description);

        $this->commandTemplate = $commandTemplate;
        $this->executor = new BashTool(
            defaultTimeout: $defaultTimeout,
            maxOutputSize: $maxOutputSize,
            workingDirectory: $workingDirectory,
            allowedPatterns: $allowedPatterns,
            blockedPatterns: $blockedPatterns,
            env: $env,
        );
    }

    /**
     * Выполняет шаблонную bash-команду, подставляя значения параметров от LLM.
     *
     * Последовательность работы:
     * 1. Принимает именованные аргументы, соответствующие плейсхолдерам в шаблоне.
     * 2. Подставляет значения в шаблон через {@see PlaceholderHelper::renderWithParams()}.
     * 3. Делегирует выполнение сформированной команды внутреннему {@see BashTool}.
     * 4. Возвращает JSON-результат с stdout, stderr, exitCode и timedOut.
     *
     * Если для какого-либо плейсхолдера не передано значение, он будет заменён
     * на пустую строку (поведение PlaceholderHelper).
     *
     * @param mixed ...$args Именованные аргументы от LLM, соответствующие плейсхолдерам
     *
     * @return string JSON-строка с результатом выполнения ({@see BashResultDto::toArray()})
     */
    public function __invoke(mixed ...$args): string
    {
        /** @var array<string, mixed> $params */
        $params = $args;
        $command = PlaceholderHelper::renderWithParams($this->commandTemplate, $params);

        return $this->executor->__invoke($command);
    }

    /**
     * Возвращает шаблон bash-команды с плейсхолдерами.
     *
     * @return string Исходный шаблон, заданный при создании инструмента
     */
    public function getCommandTemplate(): string
    {
        return $this->commandTemplate;
    }

    /**
     * Извлекает имена плейсхолдеров из шаблона команды.
     *
     * Использует {@see PlaceholderHelper::collectPlaceholders()} для парсинга
     * шаблона и возвращает уникальный список имён без ведущего знака `$`.
     *
     * @return string[] Массив имён плейсхолдеров (например, ['count', 'branch'])
     */
    public function getPlaceholders(): array
    {
        return PlaceholderHelper::collectPlaceholders($this->commandTemplate);
    }

    /**
     * Проверяет соответствие плейсхолдеров в шаблоне и описанных свойств инструмента.
     *
     * Выявляет два типа проблем:
     * - Плейсхолдер в шаблоне не имеет соответствующего свойства (LLM не сможет передать значение).
     * - Свойство описано, но не используется ни в одном плейсхолдере (избыточный параметр).
     *
     * Формат элемента ошибки:
     * ```
     * [
     *     'type'    => 'missing_property' | 'unused_property',
     *     'param'   => string,   // имя плейсхолдера или свойства
     *     'message' => string,   // человекочитаемое описание
     * ]
     * ```
     *
     * @return array<int, array{type: string, param: string, message: string}> Список найденных проблем
     */
    public function validatePlaceholders(): array
    {
        $errors = [];

        $placeholders = $this->getPlaceholders();
        $propertyNames = array_map(
            static fn (ToolPropertyInterface $p): string => $p->getName(),
            $this->getProperties(),
        );

        foreach ($placeholders as $placeholder) {
            if (!in_array($placeholder, $propertyNames, true)) {
                $errors[] = [
                    'type' => 'missing_property',
                    'param' => $placeholder,
                    'message' => "Плейсхолдер \${$placeholder} в шаблоне команды не имеет соответствующего свойства инструмента.",
                ];
            }
        }

        foreach ($propertyNames as $propertyName) {
            if (!in_array($propertyName, $placeholders, true)) {
                $errors[] = [
                    'type' => 'unused_property',
                    'param' => $propertyName,
                    'message' => "Свойство «{$propertyName}» описано, но не используется в шаблоне команды.",
                ];
            }
        }

        return $errors;
    }

    /**
     * Устанавливает таймаут по умолчанию для выполнения команды.
     *
     * Значение передаётся внутреннему {@see BashTool}. При превышении таймаута
     * процесс получает SIGTERM, затем SIGKILL.
     *
     * @param int $defaultTimeout Таймаут в секундах
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setDefaultTimeout(int $defaultTimeout): self
    {
        $this->executor->setDefaultTimeout($defaultTimeout);

        return $this;
    }

    /**
     * Устанавливает максимальный размер захваченного вывода (stdout и stderr).
     *
     * При превышении лимита вывод обрезается с пометкой «[вывод обрезан]».
     *
     * @param int $maxOutputSize Максимальный размер в байтах
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setMaxOutputSize(int $maxOutputSize): self
    {
        $this->executor->setMaxOutputSize($maxOutputSize);

        return $this;
    }

    /**
     * Устанавливает рабочую директорию для выполнения команды.
     *
     * Сформированная из шаблона команда будет запущена с указанной директорией
     * в качестве cwd.
     *
     * @param string $workingDirectory Абсолютный путь к рабочей директории
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setWorkingDirectory(string $workingDirectory): self
    {
        $this->executor->setWorkingDirectory($workingDirectory);

        return $this;
    }

    /**
     * Устанавливает белый список regex-шаблонов разрешённых команд.
     *
     * Проверяется уже сформированная (после подстановки плейсхолдеров) команда.
     * Пустой массив снимает ограничение.
     *
     * @param string[] $allowedPatterns Массив regex-шаблонов
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setAllowedPatterns(array $allowedPatterns): self
    {
        $this->executor->setAllowedPatterns($allowedPatterns);

        return $this;
    }

    /**
     * Устанавливает чёрный список regex-шаблонов запрещённых команд.
     *
     * Проверяется сформированная команда. Если совпадение найдено, команда
     * будет отклонена даже при наличии в белом списке.
     *
     * @param string[] $blockedPatterns Массив regex-шаблонов
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setBlockedPatterns(array $blockedPatterns): self
    {
        $this->executor->setBlockedPatterns($blockedPatterns);

        return $this;
    }

    /**
     * Устанавливает дополнительные переменные окружения для дочернего процесса.
     *
     * Переменные добавляются к текущему окружению при запуске команды.
     *
     * @param array<string,string> $env Ассоциативный массив «имя => значение»
     *
     * @return self Текущий экземпляр для цепочки вызовов
     */
    public function setEnv(array $env): self
    {
        $this->executor->setEnv($env);

        return $this;
    }
}
