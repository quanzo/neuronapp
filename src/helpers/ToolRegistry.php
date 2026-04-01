<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\tools\ATool;
use app\modules\neuron\tools\ChunckSizeTool;
use app\modules\neuron\tools\ChunckViewTool;
use app\modules\neuron\tools\ChunckGrepTool;
use app\modules\neuron\tools\GitSummaryTool;
use app\modules\neuron\tools\GlobTool;
use app\modules\neuron\tools\GrepTool;
use app\modules\neuron\tools\VarExistTool;
use app\modules\neuron\tools\VarGetTool;
use app\modules\neuron\tools\VarListTool;
use app\modules\neuron\tools\VarPadTool;
use app\modules\neuron\tools\VarSetTool;
use app\modules\neuron\tools\VarUnsetTool;
use app\modules\neuron\tools\WebFetchTool;
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
            'wiki_search'    => new WikiSearchTool(),
            'ru_wiki_search' => new RuWikiSearchTool(),
            'uni_search'     => new UniSearchTool(),
            'git_summary'    => new GitSummaryTool(),
            'var_set'        => new VarSetTool(),
            'var_get'        => new VarGetTool(),
            'var_list'       => new VarListTool(),
            'var_exist'      => new VarExistTool(),
            'var_unset'      => new VarUnsetTool(),
            'var_pad'        => new VarPadTool(),
            'todo_goto'      => new TodoGotoTool(),
            'todo_completed' => new TodoCompletedTool(),
            'chunk_size'     => new ChunckSizeTool(),
            'chunk_view'     => new ChunckViewTool(),
            'chunk_grep'     => new ChunckGrepTool(),
            'glob'           => new GlobTool(),
            'grep'           => new GrepTool(),
            // Единый инструмент. 'http_fetch' сохранён как алиас (для обратной совместимости),
            // но реализован тем же классом WebFetchTool.
            'http_fetch'     => new WebFetchTool(name: 'http_fetch'),
            'web_fetch'      => new WebFetchTool(),
            default          => null,
        };
        if ($tool && $tool instanceof ATool) {
            $tool->setAgentCfg($agentCfg);
        }
        return $tool;
    }
}
