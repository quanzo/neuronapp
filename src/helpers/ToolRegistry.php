<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\tools\GitSummaryTool;
use app\modules\neuron\tools\WikiSearchTool;
use app\modules\neuron\tools\RuWikiSearchTool;
use app\modules\neuron\tools\UniSearchTool;
use NeuronAI\Tools\ToolInterface;

/**
 * Реестр встроенных инструментов (tools), доступных по коротким именам.
 *
 * Используется для подключения инструментов, перечисленных в опции "tools"
 * текстовых компонентов (skills, todolist и др.), в сессионную конфигурацию
 * агента {@see ConfigurationAgent}.
 */
final class ToolRegistry
{
    /**
     * Создаёт экземпляр встроенного инструмента по его имени.
     *
     * @param string              $name     Короткое имя инструмента (например, 'wiki_search').
     * @param ConfigurationAgent  $agentCfg Конфигурация агента, в контексте которой работает инструмент.
     *
     * @return ToolInterface|null Экземпляр инструмента или null, если имя не распознано.
     */
    public static function makeTool(string $name, ConfigurationAgent $agentCfg): ?ToolInterface
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return match ($name) {
            'wiki_search' => new WikiSearchTool(),
            'ru_wiki_search' => new RuWikiSearchTool(),
            'uni_search' => new UniSearchTool(),
            'git_summary' => new GitSummaryTool(),
            default => null,
        };
    }
}

