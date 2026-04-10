<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\ToolEventDto;
use app\modules\neuron\classes\dto\events\ToolErrorEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use NeuronAI\Tools\Tool;

/**
 * Базовый класс инструментов модуля с публикацией событий.
 *
 * Логирование выполняется подписчиками EventBus на события tool.*.
 */
abstract class ATool extends Tool
{
    private $_agentCfg = null;

    /**
     * Агент, который использует инструмент
     *
     * @return ConfigurationAgent|null
     */
    public function getAgentCfg(): ?ConfigurationAgent
    {
        return $this->_agentCfg;
    }

    /**
     * Задать агента для этого инструмента
     *
     * @param ConfigurationAgent $agentCfg
     * @return static
     */
    public function setAgentCfg(ConfigurationAgent $agentCfg): static
    {
        $this->_agentCfg = $agentCfg;
        return $this;
    }

    /**
     * Выполняет инструмент с публикацией событий начала, завершения и ошибок.
     *
     * @throws \Throwable Пробрасывает исключение после публикации события ошибки.
     */
    public function execute(): void
    {
        EventBus::trigger(
            EventNameEnum::TOOL_STARTED->value,
            static::class,
            $this->buildToolEventDto()
        );

        try {
            parent::execute();
            EventBus::trigger(
                EventNameEnum::TOOL_COMPLETED->value,
                static::class,
                $this->buildToolEventDto()
            );
        } catch (\Throwable $e) {
            $errorDto = $this->buildToolErrorEventDto();
            $errorDto->setErrorClass($e::class);
            $errorDto->setErrorMessage($e->getMessage());
            EventBus::trigger(
                EventNameEnum::TOOL_FAILED->value,
                static::class,
                $errorDto
            );
            throw $e;
        }
    }

    /**
     * Создаёт DTO события инструмента.
     */
    protected function buildToolEventDto(): ToolEventDto
    {
        $toolName = $this->getName();
        $agentCfg = $this->getAgentCfg();
        $sessionKey = $agentCfg?->getSessionKey() ?? '';
        return (new ToolEventDto())
            ->setSessionKey($sessionKey)
            ->setRunId('')
            ->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM))
            ->setAgent($this->getAgentCfg())
            ->setToolName($toolName);
    }

    /**
     * Создаёт DTO ошибки события инструмента.
     */
    protected function buildToolErrorEventDto(): ToolErrorEventDto
    {
        $toolName = $this->getName();
        $agentCfg = $this->getAgentCfg();
        $sessionKey = $agentCfg?->getSessionKey() ?? '';
        $dto = new ToolErrorEventDto();
        $dto->setSessionKey($sessionKey);
        $dto->setRunId('');
        $dto->setTimestamp((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $dto->setAgent($this->getAgentCfg());
        $dto->setToolName($toolName);
        return $dto;
    }
}
