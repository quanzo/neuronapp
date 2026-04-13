<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
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
        $this->applyStartDirDefaultsFromAgentCfg($agentCfg);
        return $this;
    }

    /**
     * Применяет директорию старта (ConfigurationApp::getStartDir()) к типовым свойствам инструмента.
     *
     * Многие инструменты имеют свойства вроде basePath/workingDirectory и раньше по умолчанию
     * брали {@see getcwd()}. Но рабочая директория процесса может меняться (chdir),
     * поэтому закрепляемся на директории старта приложения.
     *
     * @param ConfigurationAgent $agentCfg Конфигурация агента текущей сессии.
     */
    protected function applyStartDirDefaultsFromAgentCfg(ConfigurationAgent $agentCfg): void
    {
        // Сначала проверяем, есть ли смысл вычислять startDir (это может требовать инициализации singleton'а).
        if (!$this->hasEmptyStringProperty('basePath') && !$this->hasEmptyStringProperty('workingDirectory')) {
            return;
        }

        $configApp = $agentCfg->getConfigurationApp();
        if ($configApp === null) {
            try {
                $configApp = ConfigurationApp::getInstance();
            } catch (\Throwable) {
                return;
            }
        }

        $startDir = $configApp->getStartDir();

        $this->setObjectPropertyIfEmpty('basePath', $startDir);
        $this->setObjectPropertyIfEmpty('workingDirectory', $startDir);
    }

    /**
     * Проверяет наличие строкового свойства со значением '' (или нестроковым значением).
     *
     * @param string $propertyName
     */
    private function hasEmptyStringProperty(string $propertyName): bool
    {
        try {
            $ref = new \ReflectionObject($this);
            if (!$ref->hasProperty($propertyName)) {
                return false;
            }
            $prop = $ref->getProperty($propertyName);
            if ($prop->isStatic()) {
                return false;
            }
            $prop->setAccessible(true);
            $current = $prop->getValue($this);
            return !is_string($current) || $current === '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Устанавливает свойство объекта в значение по умолчанию, если оно пустое.
     *
     * Используем Reflection, т.к. свойства объявлены в конкретных Tool-классах и могут быть protected.
     *
     * @param string $propertyName Имя свойства.
     * @param string $value        Значение по умолчанию.
     */
    private function setObjectPropertyIfEmpty(string $propertyName, string $value): void
    {
        try {
            $ref = new \ReflectionObject($this);
            if (!$ref->hasProperty($propertyName)) {
                return;
            }
            $prop = $ref->getProperty($propertyName);
            if ($prop->isStatic()) {
                return;
            }
            $prop->setAccessible(true);
            $current = $prop->getValue($this);
            if (is_string($current) && $current !== '') {
                return;
            }
            $prop->setValue($this, $value);
        } catch (\Throwable) {
            // В случае необычной реализации tool — не мешаем работе инструмента.
            return;
        }
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
