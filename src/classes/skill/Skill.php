<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\skill;

use Amp\Future;
use app\modules\neuron\classes\AbstractPromptWithParams;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\params\ParamListDto;
use app\modules\neuron\classes\dto\attachments\AttachmentDto;
use app\modules\neuron\helpers\FileContextHelper;
use app\modules\neuron\helpers\OptionsHelper;
use app\modules\neuron\helpers\PlaceholderHelper;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\interfaces\ISkill;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Класс текстового навыка (Skill).
 *
 * Хранит текстовый шаблон с опциями и поддерживает подстановку
 * именованных параметров вида $paramName при получении финального текста.
 * Имя параметра — только латинские буквы [a-zA-Z]+, регистр учитывается.
 */
class Skill extends AbstractPromptWithParams implements ISkill
{
    private string $name;

    /**
     * Создает навык на основе входного текстового описания.
     *
     * Текст может содержать:
     *  - только тело навыка;
     *  - блок опций и тело, разделенные линиями из '-';
     *  - только блок опций (без тела);
     *  - быть пустым (без опций и тела).
     *
     * @param string               $input     Полный текст описания навыка.
     * @param string               $name      Имя навыка (имя файла с поддиректорией, если есть).
     * @param ConfigurationApp|null $configApp Экземпляр конфигурации приложения для разрешения зависимостей.
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
     * @inheritDoc
     */
    protected function getComponentName(): string
    {
        return $this->getName();
    }

    /**
     * Возвращает имена навыков (Skill), которые нужно подключить при исполнении.
     * Берутся из опции "skills" — строка с именами через запятую (пробелы обрезаются),
     * при этом исключается имя текущего skill, чтобы избежать самоссылок.
     *
     * @return list<string>
     */
    public function getNeedSkills(): array
    {
        return $this->parseSkills(true);
    }

    /**
     * Определяет, нужно ли выполнять навык с чистым контекстом.
     *
     * Чистый контекст — использование клона конфигурации агента (cloneForSession),
     * чтобы не изменять основное состояние агента (историю, кеш).
     * Опция задаётся параметром "pure_context" в блоке опций навыка.
     *
     * @return bool true, если опция pure_context задана как 1 или 'true'; false — если не задана, 0 или 'false'.
     */
    public function isPureContext(): bool
    {
        $value = $this->getOptions()['pure_context'] ?? null;
        return OptionsHelper::toBool($value);
    }

    /**
     * Проверяет корректность настройки Skill и возвращает список найденных проблем.
     *
     * Формат элемента ошибки:
     *  - type: строковый код ошибки
     *  - message: человекочитаемое описание
     *  - param: опционально, имя параметра, к которому относится ошибка
     *
     * @return array<int, array{type:string, message:string, param?:string}>
     */
    public function checkErrors(): array
    {
        return $this->getErrors();
    }

    /**
     * Возвращает текст навыка с подставленными именованными параметрами.
     *
     * Каждый ключ массива соответствует плейсхолдеру в шаблоне:
     * ключ "query" заменяет все вхождения "$query" и т.д.
     * Имя параметра — только латинские буквы [a-zA-Z]+, регистр учитывается.
     * Плейсхолдеры без переданных значений заменяются на пустую строку.
     *
     * @param array<string, mixed> $params Именованные параметры для подстановки.
     */
    public function getSkill(array $params = []): string
    {
        return PlaceholderHelper::renderWithParams($this->getBody(), $params);
    }

    /**
     * Генерирует LLM-инструмент ({@see Tool}) на основе навыка.
     *
     * При вызове инструмента подставляются параметры в шаблон навыка,
     * результат отправляется в LLM через переданную конфигурацию агента,
     * и возвращается ответ модели.
     *
     * Имя инструмента строится из имени файла навыка (с поддиректорией),
     * описание берётся из опции "description", параметры — из опции "params".
     *
     * Формат "params" — JSON-объект, где ключ = имя параметра, значение:
     *  - строка (тип, напр. "string") или
     *  - объект {"type": "...", "description": "...", "required": true/false}.
     *
     * @param MessageRole $role Роль сообщения, отправляемого агенту.
     */
    public function getTool(MessageRole $role = MessageRole::USER): Tool
    {
        // Критичные ошибки конфигурации skill считаем поводом не строить инструмент и явно сообщить об этом.
        $errors = $this->checkErrors();
        foreach ($errors as $error) {
            if (in_array($error['type'], ['missing_param_definition', 'invalid_params_type', 'invalid_params_json', 'invalid_param_name', 'invalid_param_definition_type', 'invalid_param_type_value', 'invalid_param_description_value'], true)) {
                $message = $error['message'] ?? 'Некорректная конфигурация skill.';
                throw new \RuntimeException(
                    sprintf('Некорректная конфигурация skill "%s": %s', $this->getName(), $message)
                );
            }
        }

        $options = $this->getOptions();

        $toolName = str_replace('/', '__', $this->name);
        $description = $options['description'] ?? null;

        $tool = new Tool($toolName, is_string($description) ? $description : null);

        $paramsOption = $options['params'] ?? null;
        [$paramList, $paramErrors] = ParamListDto::tryFromOptionValue($paramsOption);
        // Ошибки должны были отфильтроваться выше (через checkErrors), но на всякий случай.
        if ($paramErrors !== []) {
            throw new \RuntimeException(
                sprintf('Некорректная конфигурация skill "%s": %s', $this->getName(), $paramErrors[0]['message'] ?? 'Ошибка в params')
            );
        }

        foreach (($paramList?->all() ?? []) as $param) {
            $tool->addProperty(new ToolProperty(
                $param->getName(),
                PropertyType::from($param->getType()),
                $param->getDescription(),
                $param->isRequired(),
            ));
        }

        $skillName = $this->getName();
        $tool->setCallable(function (mixed ...$args) use ($role, $skillName): mixed {
            $agentCfg = $this->getConfigurationAgent();
            $logger  = $agentCfg->getLoggerWithContext();
            $context = ['skill' => $skillName];
            $logger->info('Skill вызван', $context);
            try {
                $future = $this->execute($role, [], $args);
                $result = $future->await();
                return $result;
            } catch (\Throwable $e) {
                $logger->error('Ошибка выполнения skill', array_merge($context, ['exception' => $e]));
                throw $e;
            }
        });

        return $tool;
    }

    /**
     * Выполняет навык, отправляя сгенерированный текст и дополнительные вложения в LLM.
     *
     * При isPureContext() используется клон конфигурации агента (чистый контекст).
     *
     * @param MessageRole              $role        Роль сообщения.
     * @param AttachmentDto[]          $attachments Дополнительные вложения, передаваемые вместе с текстом навыка.
     * @param array<string,mixed>|null $params      Параметры для подстановки в шаблон навыка.
     *
     * @return Future<mixed> Результат выполнения запроса к LLM.
     */
    public function execute(
        MessageRole $role = MessageRole::USER,
        array $attachments = [],
        ?array $params = null
    ): Future {
        $agentCfg = $this->getConfigurationAgent();
        $logger   = $agentCfg->getLogger();
        $context  = array_merge($agentCfg->getLogContext(), ['skill' => $this->getName()]);
        $logger->info('Skill started', $context);

        $text = $this->getSkill($params ?? []);

        $configApp = $this->getConfigurationApp();
        if ($configApp !== null) {
            /**
             * Здесь находим в тексте skill указание, на подключение в контекст выполнения, файлов
             * Это конструкции вида: @relative/path/to/file.txt
             * {@see FileContextHelper::buildContextAttachments}
             */
            $contextFiles = FileContextHelper::buildContextAttachments($this->getBody(), $configApp);
            if ($contextFiles['attachments'] !== []) {
                $attachments = array_merge($attachments, $contextFiles['attachments']);
            }
        }

        return \Amp\async(function () use ($agentCfg, $text, $role, $attachments): mixed {

            $sessionCfg = $this->isPureContext()
                ? $agentCfg->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY) // здесь агент без истории сообщений
                : $agentCfg->cloneForSession(ChatHistoryCloneMode::COPY_CONTEXT); // здесь агент с копией, которая не влияет на сессию

            $configApp = $this->getConfigurationApp();

            if ($configApp !== null && $this->getNeedSkills() !== []) {
                // здесь передаем в skill другие навыки, которые указаны в его параметрах
                $skillTools = [];
                foreach ($this->getNeedSkills() as $skillName) {
                    $skill = $configApp->getSkill($skillName);
                    if ($skill !== null) {
                        // если в блоке настроек skill не указан используемый агент, то берется $sessionCfg
                        $skill->setDefaultConfigurationAgent($sessionCfg);
                        $skillTools[] = $skill->getTool($role);
                    }
                }
                if ($skillTools !== []) {
                    $sessionCfg->tools = array_merge($agentCfg->getTools(), $skillTools);
                }
            }

            try {
                $message = new NeuronMessage($role, $text);
                $result = $sessionCfg->sendMessageWithAttachments($message, $attachments);
                $agentCfg->getLogger()->info('Skill completed', array_merge($agentCfg->getLogContext(), ['skill' => $this->getName()]));
                return $result;
            } catch (\Throwable $e) {
                $agentCfg->getLogger()->error('Ошибка выполнения skill', array_merge($agentCfg->getLogContext(), ['skill' => $this->getName(), 'exception' => $e]));
                throw $e;
            }
        });
    }
}
