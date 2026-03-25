<?php

declare(strict_types=1);

namespace app\modules\neuron\traits;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\helpers\ToolRegistry;
use app\modules\neuron\tools\ATool;
use NeuronAI\Chat\Enums\MessageRole;

/**
 * Трейт для компонент, которые могут подключать навыки (Skill) как инструменты LLM.
 *
 * Инкапсулирует общую логику:
 *  - чтения опции "skills" через {@see HasNeedSkillsTrait::getNeedSkills()};
 *  - разрешения навыков через {@see ConfigurationApp::getSkill()};
 *  - установки конфигурации агента по умолчанию для каждого skill;
 *  - добавления инструментов навыков в набор tools сессии.
 *
 * Ожидания к классу-носителю:
 *  - реализует метод {@see getConfigurationApp()} и возвращает {@see ConfigurationApp|null};
 *  - предоставляет метод {@see getNeedSkills()} (например, через {@see HasNeedSkillsTrait}).
 *  - реализует метод getConfigurationAgent
 */
trait AttachesSkillToolsTrait
{
    /**
     * Подключает зависимые навыки к сессионной конфигурации агента.
     *
     * @param ConfigurationAgent $sessionCfg Конфигурация агента для текущей сессии (будет модифицирована).
     * @param MessageRole        $role       Роль сообщения, с которой будут вызываться инструменты навыков.
     */
    protected function attachSkillToolsToSession(
        ConfigurationAgent $sessionCfg,
        MessageRole $role
    ): void {
        /** @var ConfigurationApp|null $configApp */
        $configApp = $this->getConfigurationApp();
        if ($configApp === null) {
            return;
        }

        $sessionTools = $sessionCfg->getTools();

        $needSkills = $this->getNeedSkills();
        if ($needSkills !== []) {
            $skillTools = [];
            foreach ($needSkills as $skillName) {
                $skill = $configApp->getSkill($skillName);
                if (!$skill instanceof Skill) {
                    continue;
                }

                // если в блоке настроек skill не указан используемый агент, то берется $sessionCfg
                $skill->setDefaultConfigurationAgent($sessionCfg);
                $skillTools[] = $skill->getTool($role);
            }

            if ($skillTools !== []) {
                $sessionTools = array_merge($sessionTools, $skillTools);
            }
        }

        if (method_exists($this, 'getNeedTools')) {
            /** @var list<string> $needTools */
            $needTools = $this->getNeedTools();
            foreach ($needTools as $toolName) {
                $tool = ToolRegistry::makeTool($toolName, $sessionCfg);
                if ($tool === null) {
                    continue;
                }
                if ($tool instanceof ATool) {
                    /**
                     * В инструмент пробрасываем конфиг агента и его логгер
                     */
                    /*
                    $a = $skill->getConfigurationAgent();
                    $tool->setAgentCfg($a);
                    */
                    $tool->setAgentCfg($sessionCfg);
                }
                $sessionTools[] = $tool;
            }
        }

        $sessionCfg->tools = $sessionTools;
    }
}
