<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\skill;

use Amp\Future;
use app\modules\neuron\classes\AbstractPromptWithParams;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\params\ParamListDto;
use app\modules\neuron\classes\dto\attachments\AttachmentDto;
use app\modules\neuron\classes\dto\cmd\CmdDto;
use app\modules\neuron\classes\dto\events\RunEventDto;
use app\modules\neuron\classes\dto\events\SkillEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\helpers\FileContextHelper;
use app\modules\neuron\helpers\OptionsHelper;
use app\modules\neuron\helpers\PlaceholderHelper;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\helpers\AttachmentHelper;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\interfaces\ISkill;
use app\modules\neuron\traits\HasNeedSkillsTrait;
use app\modules\neuron\traits\AttachesSkillToolsTrait;
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
    use HasNeedSkillsTrait;
    use AttachesSkillToolsTrait;

    /**
     * Создает навык на основе входного текстового описания.
     *
     * Текст может содержать:
     *  - только тело навыка;
     *  - блок опций и тело, разделенные линиями из '-';
     *  - только блок опций (без тела);
     *  - быть пустым (без опций и тела).
     *
     * @param string                $input     Полный текст описания навыка.
     * @param string                $name      Имя навыка (имя файла с поддиректорией, если есть).
     * @param ConfigurationApp|null $configApp Экземпляр конфигурации приложения для разрешения зависимостей.
     */
    public function __construct(string $input, string $name = '', ?ConfigurationApp $configApp = null)
    {
        parent::__construct($input, $name, $configApp);
    }

    /**
     * Возвращает текст навыка с подставленными именованными параметрами.
     *
     * Каждый ключ массива соответствует плейсхолдеру в шаблоне:
     * ключ "query" заменяет все вхождения "$query" и т.д.
     * Имя параметра — только латинские буквы [a-zA-Z]+, регистр учитывается.
     * Плейсхолдеры без переданных значений заменяются на пустую строку.
     *
     * Итоговый набор параметров формируется с учётом:
     *  - значений по умолчанию из описания params (default);
     *  - переданных $params (имеют приоритет над default).
     *
     * @param array<string, mixed> $params Именованные параметры для подстановки.
     */
    public function getSkill(array $params = []): string
    {
        $effectiveParams = $this->buildEffectiveParams($params, null);

        return PlaceholderHelper::renderWithParams($this->getBody(), $effectiveParams);
    }

    /**
     * Возвращает список управляющих команд, определённых в теле навыка.
     *
     * Команды ищутся по синтаксису "@@name(...)" в исходном тексте тела
     * (без комментариев, так как {@see CommentsHelper::stripComments()} уже
     * применён в конструкторе).
     *
     * @return list<CmdDto> Массив DTO-команд в порядке появления в тексте.
     */
    public function getCmdList(): array
    {
        return FileContextHelper::extractCmdFromBody($this->getBody());
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
        $description = $this->getDescription();

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
            try {
                $future = $this->execute($role, [], $args);
                $result = $future->await();
                return $result;
            } catch (\Throwable $e) {
                EventBus::trigger(
                    EventNameEnum::SKILL_FAILED->value,
                    $this,
                    $this->buildSkillEventDto('', '')
                        ->setSkillName($skillName)
                        ->setSuccess(false)
                        ->setErrorClass($e::class)
                        ->setErrorMessage($e->getMessage())
                );
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
        $runId     = $this->generateRunId();
        EventBus::trigger(
            EventNameEnum::RUN_STARTED->value,
            $this,
            $this->buildRunEventDto($agentCfg->getSessionKey() ?? '', $runId, 0)->setSuccess(true)
        );
        EventBus::trigger(
            EventNameEnum::SKILL_STARTED->value,
            $this,
            $this->buildSkillEventDto($agentCfg->getSessionKey() ?? '', $runId)->setSuccess(true)
        );

        $text = $this->getSkill($params ?? []);

        $configApp = $this->getConfigurationApp();
        if ($configApp !== null) {
            /**
             * Здесь находим в тексте skill указание, на подключение в контекст выполнения, файлов
             * Это конструкции вида: @relative/path/to/file.txt
             * {@see AttachmentHelper::buildContextAttachments}
             */
            $contextFiles = AttachmentHelper::buildContextAttachments($this->getBody(), $configApp);
            if ($contextFiles['attachments'] !== []) {
                $attachments = array_merge($attachments, $contextFiles['attachments']);
            }
        }

        return \Amp\async(function () use ($agentCfg, $text, $role, $attachments, $runId): mixed {

            $sessionCfg = $this->isPureContext()
                ? $agentCfg->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY) // здесь агент без истории сообщений
                : $agentCfg->cloneForSession(ChatHistoryCloneMode::COPY_CONTEXT); // здесь агент с копией, которая не влияет на сессию

            // здесь передаем в skill другие навыки, которые указаны в его параметрах
            $this->attachSkillToolsToSession($sessionCfg, $role);

            try {
                $message = new NeuronMessage($role, $text);
                $result = $sessionCfg->sendMessageWithAttachments($message, $attachments);
                $agentCfg->getLogger()->info('Skill completed', array_merge($agentCfg->getLogContext(), ['skill' => $this->getName()]));
                EventBus::trigger(
                    EventNameEnum::SKILL_COMPLETED->value,
                    $this,
                    $this->buildSkillEventDto($agentCfg->getSessionKey() ?? '', $runId)->setSuccess(true)
                );
                EventBus::trigger(
                    EventNameEnum::RUN_FINISHED->value,
                    $this,
                    $this->buildRunEventDto($agentCfg->getSessionKey() ?? '', $runId, 1)->setSuccess(true)
                );
                return $result;
            } catch (\Throwable $e) {
                EventBus::trigger(
                    EventNameEnum::SKILL_FAILED->value,
                    $this,
                    $this->buildSkillEventDto($agentCfg->getSessionKey() ?? '', $runId)
                        ->setSuccess(false)
                        ->setErrorClass($e::class)
                        ->setErrorMessage($e->getMessage())
                );
                EventBus::trigger(
                    EventNameEnum::RUN_FAILED->value,
                    $this,
                    $this->buildRunEventDto($agentCfg->getSessionKey() ?? '', $runId, 0)
                        ->setSuccess(false)
                        ->setErrorClass($e::class)
                        ->setErrorMessage($e->getMessage())
                );
                throw $e;
            }
        });
    }

    /**
     * Значение по умолчанию для опции pure_context у Skill.
     *
     * Для текстового навыка по умолчанию используется общий контекст
     * агента, поэтому при отсутствии опции pure_context метод
     * {@see isPureContext()} возвращает false.
     */
    protected function getDefaultPureContext(): bool
    {
        return false;
    }

    /**
     * Создает DTO run-события для Skill.
     */
    private function buildRunEventDto(string $sessionKey, string $runId, int $steps): RunEventDto
    {
        return (new RunEventDto())
            ->setSessionKey($sessionKey)
            ->setRunId($runId)
            ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
            ->setAgent($this->getConfigurationAgent())
            ->setType('skill')
            ->setName($this->getName())
            ->setSteps($steps);
    }

    /**
     * Создает DTO skill-события.
     */
    private function buildSkillEventDto(string $sessionKey, string $runId): SkillEventDto
    {
        return (new SkillEventDto())
            ->setSessionKey($sessionKey)
            ->setRunId($runId)
            ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
            ->setAgent($this->getConfigurationAgent())
            ->setSkillName($this->getName());
    }

    /**
     * Генерирует идентификатор выполнения.
     */
    private function generateRunId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
