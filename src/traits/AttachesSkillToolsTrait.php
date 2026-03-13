<?php

declare(strict_types=1);

namespace app\modules\neuron\traits;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\skill\Skill;
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

        $needSkills = $this->getNeedSkills();
        if ($needSkills === []) {
            return;
        }

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

        if ($skillTools === []) {
            return;
        }

        $sessionCfg->tools = array_merge($sessionCfg->getTools(), $skillTools);
    }
}
