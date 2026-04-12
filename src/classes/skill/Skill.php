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
use app\modules\neuron\classes\dto\events\RunErrorEventDto;
use app\modules\neuron\classes\dto\events\SkillEventDto;
use app\modules\neuron\classes\dto\events\SkillErrorEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\helpers\FileContextHelper;
use app\modules\neuron\helpers\OptionsHelper;
use app\modules\neuron\helpers\PlaceholderHelper;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\helpers\AttachmentHelper;
use app\modules\neuron\helpers\ChatHistoryEditHelper;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\helpers\LlmCycleHelper;
use app\modules\neuron\interfaces\ISkill;
use app\modules\neuron\traits\HasNeedSkillsTrait;
use app\modules\neuron\traits\AttachesSkillToolsTrait;
use JsonSerializable;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
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

        $tool->setCallable(function (mixed ...$args) use ($role): mixed {
            try {
                $future = $this->execute($role, [], $args);
                $result = $future->await();
                if ($result instanceof JsonSerializable) {
                    //return $result->jsonSerialize();
                }
                if ($result instanceof NeuronMessage) {
                    return $result->getContent();
                }
                return $result;
            } catch (\Throwable $e) {
                $errorDto = $this->buildSkillErrorEventDto($this->getConfigurationAgent(), '');
                $errorDto->setParams(['args' => $args]);
                $errorDto->setErrorClass($e::class);
                $errorDto->setErrorMessage($e->getMessage());
                EventBus::trigger(
                    EventNameEnum::SKILL_FAILED->value,
                    $this,
                    $errorDto
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
        $agentCfg        = $this->getConfigurationAgent();
        $runId           = $this->generateRunId();
        $effectiveParams = $this->buildEffectiveParams($params ?? [], null);
        $baseSkillEvent  = $this->buildSkillEventDto($agentCfg, $runId)->setParams($effectiveParams);
        $baseRunEvent    = new RunEventDto();
        $baseRunEvent->setSessionKey($agentCfg->getSessionKey() ?? '')
            ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
            ->setAgent($agentCfg)
            ->setType('skill')
            ->setName($this->getName());

        $runEventDto = clone $baseRunEvent;
        EventBus::trigger(
            EventNameEnum::RUN_STARTED->value,
            $this,
            $runEventDto->setSteps(0)
        );

        $eventDto = clone $baseSkillEvent;
        EventBus::trigger(
            EventNameEnum::SKILL_STARTED->value,
            $this,
            $eventDto
        );

        $text = PlaceholderHelper::renderWithParams($this->getBody(), $effectiveParams);

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

        return \Amp\async(function () use ($agentCfg, $text, $role, $attachments, $baseSkillEvent, $baseRunEvent, $runId, $effectiveParams): mixed {

            $sessionCfg_0 = $this->isPureContext()
                ? $agentCfg->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY) // здесь агент без истории сообщений
                : $agentCfg->cloneForSession(ChatHistoryCloneMode::COPY_CONTEXT); // здесь агент с копией, которая не влияет на сессию

            if ($this->isPureContext()) {
                $sessionCfg_0->setExcludeLongTermMind(true);
            }

            /**
             * Когда выполняется skill то может быть подключение к агенту дополнительных инструментов, изменение настроек и т.п.
             * Чтобы это не влияло на агент для всех остальных - клонируем
             */
            $sessionCfg = clone $sessionCfg_0;

            // здесь передаем в skill другие навыки, которые указаны в его параметрах
            $this->attachSkillToolsToSession($sessionCfg, $role);

            try {
                $message = new NeuronMessage($role, $text);
                $result = $sessionCfg->sendMessageWithAttachments($message, $attachments);

                // здесь проверим, что LLM исполнила - спросим ее прямо
                $arRes = LlmCycleHelper::waitCycle($sessionCfg, $sessionCfg->llmMaxCycleCount, $sessionCfg->llmMaxTotalRounds);
                if ($arRes['cycles'] > 1) {
                    /* т.к. удаляем пару вопрос-ответ статус задачи то и итоговое сообщение будет последним в истории
                    $result = LlmCycleHelper::repeateResultMsg($sessionCfg);
                    */
                }

                $eventDto = clone $baseSkillEvent;
                EventBus::trigger(
                    EventNameEnum::SKILL_COMPLETED->value,
                    $this,
                    $eventDto
                );

                $runEventDto = clone $baseRunEvent;
                EventBus::trigger(
                    EventNameEnum::RUN_FINISHED->value,
                    $this,
                    $runEventDto->setSteps(1)
                );

                // берем историю работы скила и выбираем последнее сообщение - это будет результат
                $lastMessage = null;
                $history     = $sessionCfg->getChatHistory();
                $messages    = $history instanceof AbstractFullChatHistory ? $history->getFullMessages() : $history->getMessages();
                // последнее сообщение ассистента...
                for ($i = sizeof($messages) - 1; $i >= 0; $i--) {
                    if (
                        $messages[$i]->getRole() == MessageRole::ASSISTANT->value
                        && !(
                            $messages[$i] instanceof ToolCallMessage
                            || $messages[$i] instanceof ToolResultMessage
                        )
                        && $messages[$i]->getContent()
                    ) {
                        $lastMessage = $messages[$i];
                    }
                }
                if (empty($lastMessage)) {
                    $lastMessage = ChatHistoryEditHelper::getLastMessage($sessionCfg->getChatHistory());
                }
                if (!$lastMessage->getContent()) {
                    $xxx = 1;
                }
                return $lastMessage;
                /* так не будем делать ибо работа skill после первого ответа может не закончится а закончится после прохождения цикла LlmCycleHelper
                return $result;
                */
            } catch (\Throwable $e) {
                $skillErrDto = $this->buildSkillErrorEventDto($agentCfg, $runId);
                $skillErrDto->setParams($effectiveParams);
                $skillErrDto->setErrorClass($e::class);
                $skillErrDto->setErrorMessage($e->getMessage());
                EventBus::trigger(
                    EventNameEnum::SKILL_FAILED->value,
                    $this,
                    $skillErrDto
                );

                $runErrDto = $this->buildRunErrorEventDto($agentCfg, $runId);
                $runErrDto->setType('skill');
                $runErrDto->setName($this->getName());
                $runErrDto->setSteps(0);
                $runErrDto->setErrorClass($e::class);
                $runErrDto->setErrorMessage($e->getMessage());
                EventBus::trigger(
                    EventNameEnum::RUN_FAILED->value,
                    $this,
                    $runErrDto
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
     * Создает DTO skill-события.
     */
    private function buildSkillEventDto(ConfigurationAgent $agentCfg, string $runId): SkillEventDto
    {
        return (new SkillEventDto())
            ->setSessionKey($agentCfg->getSessionKey() ?? '')
            ->setRunId($runId)
            ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
            ->setAgent($agentCfg)
            ->setSkill($this);
    }

    /**
     * Создает DTO ошибки skill-события.
     */
    private function buildSkillErrorEventDto(ConfigurationAgent $agentCfg, string $runId): SkillErrorEventDto
    {
        $dto = new SkillErrorEventDto();
        $dto->setSessionKey($agentCfg->getSessionKey() ?? '');
        $dto->setRunId($runId);
        $dto->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $dto->setAgent($agentCfg);
        $dto->setSkill($this);
        return $dto;
    }

    /**
     * Создает DTO ошибки run-события.
     */
    private function buildRunErrorEventDto(ConfigurationAgent $agentCfg, string $runId): RunErrorEventDto
    {
        $dto = new RunErrorEventDto();
        $dto->setSessionKey($agentCfg->getSessionKey() ?? '');
        $dto->setRunId($runId);
        $dto->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $dto->setAgent($agentCfg);
        return $dto;
    }

    /**
     * Генерирует идентификатор выполнения.
     */
    private function generateRunId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
