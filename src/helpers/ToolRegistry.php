<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\tools\ATool;
use app\modules\neuron\tools\GitSummaryTool;
use app\modules\neuron\tools\StoreExistTool;
use app\modules\neuron\tools\StoreListTool;
use app\modules\neuron\tools\StoreLoadTool;
use app\modules\neuron\tools\StoreSaveTool;
use app\modules\neuron\tools\StoreDeleteTool;
use app\modules\neuron\tools\StorePadTool;
use app\modules\neuron\tools\WikiSearchTool;
use app\modules\neuron\tools\RuWikiSearchTool;
use app\modules\neuron\tools\TodoGotoTool;
use app\modules\neuron\tools\TodoCompletedTool;
use app\modules\neuron\tools\UniSearchTool;
use NeuronAI\Tools\ToolInterface;

/**
 * Реестр встроенных инструментов (tools), доступных по коротким именам.
 *
 * Используется для подключения инструментов, перечисленных в опции "tools"
 * текстовых компонентов (skills, todolist и др.), в сессионную конфигурацию
 * агента {@see ConfigurationAgent}.
 */
class ToolRegistry
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

        $tool = match ($name) {
            'wiki_search'         => new WikiSearchTool(),
            'ru_wiki_search'      => new RuWikiSearchTool(),
            'uni_search'          => new UniSearchTool(),
            'git_summary'         => new GitSummaryTool(),
            'store_save'          => new StoreSaveTool(),
            'store_load'          => new StoreLoadTool(),
            'store_list'          => new StoreListTool(),
            'store_exist'         => new StoreExistTool(),
            'store_delete'        => new StoreDeleteTool(),
            'store_pad'           => new StorePadTool(),
            'todo_goto'           => new TodoGotoTool(),
            'todo_completed'      => new TodoCompletedTool(),
            default => null,
        };
        if ($tool && $tool instanceof ATool) {
            $tool->setAgentCfg($agentCfg);
        }
        return $tool;
    }
}
