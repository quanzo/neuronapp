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
 *
 * Также позволяет регистрировать новые инструменты в реестр на этапе bootstrap
 * приложения (например, из модулей/плагинов).
 *
 * Пример:
 * <code>
 * ToolRegistry::register(
 *     'my_tool',
 *     static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new MyTool()
 * );
 * ToolRegistry::registerAlias('http_fetch', 'web_fetch', overwrite: true);
 *
 * $tool = ToolRegistry::makeTool('my_tool', $cfg);
 * </code>
 */
class ToolRegistry
{
    /**
     * Реестр фабрик инструментов.
     *
     * Ключ: короткое имя инструмента.
     * Значение: фабрика, создающая {@see ToolInterface} для указанного конфига агента.
     *
     * @var array<string, callable(string, ConfigurationAgent): ToolInterface>|null
     */
    private static ?array $factories = null;

    /**
     * Инициализирует базовый список встроенных инструментов (tools).
     *
     * @return void
     */
    private static function ensureInitialized(): void
    {
        if (self::$factories !== null) {
            return;
        }

        self::$factories = [
            'wiki_search'    => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new WikiSearchTool(),
            'ru_wiki_search' => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new RuWikiSearchTool(),
            'uni_search'     => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new UniSearchTool(),
            'git_summary'    => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new GitSummaryTool(),
            'var_set'        => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new VarSetTool(),
            'var_get'        => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new VarGetTool(),
            'var_list'       => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new VarListTool(),
            'var_exist'      => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new VarExistTool(),
            'var_unset'      => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new VarUnsetTool(),
            'var_pad'        => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new VarPadTool(),
            'todo_goto'      => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new TodoGotoTool(),
            'todo_completed' => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new TodoCompletedTool(),
            'chunk_size'     => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new ChunckSizeTool(),
            'chunk_view'     => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new ChunckViewTool(),
            'chunk_grep'     => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new ChunckGrepTool(),
            'glob'           => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new GlobTool(),
            'grep'           => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new GrepTool(),
            // Единый инструмент. 'http_fetch' сохранён как алиас (для обратной совместимости),
            // но реализован тем же классом WebFetchTool (с другим публичным именем).
            'http_fetch'     => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new WebFetchTool(name: 'http_fetch'),
            'web_fetch'      => static fn(string $name, ConfigurationAgent $cfg): ToolInterface => new WebFetchTool(),
        ];
    }

    /**
     * Регистрирует новый инструмент или переопределяет существующий.
     *
     * @param string   $name      Короткое имя инструмента.
     * @param callable $factory   Фабрика: fn(string, ConfigurationAgent): {@see ToolInterface}
     * @param bool     $overwrite Разрешить перезапись существующего имени.
     *
     * @return void
     */
    public static function register(string $name, callable $factory, bool $overwrite = false): void
    {
        self::ensureInitialized();

        $name = trim($name);
        if ($name === '') {
            return;
        }

        if (!$overwrite && isset(self::$factories[$name])) {
            return;
        }

        self::$factories[$name] = $factory;
    }

    /**
     * Регистрирует алиас (альтернативное имя) на существующий инструмент.
     *
     * Алиас указывает на фабрику целевого инструмента. Это значит, что алиас
     * повторяет реализацию целевого инструмента "как есть".
     *
     * @param string $alias     Имя алиаса.
     * @param string $target    Имя уже зарегистрированного инструмента.
     * @param bool   $overwrite Разрешить перезапись существующего имени.
     *
     * @return void
     */
    public static function registerAlias(string $alias, string $target, bool $overwrite = false): void
    {
        self::ensureInitialized();

        $alias = trim($alias);
        $target = trim($target);
        if ($alias === '' || $target === '') {
            return;
        }

        $factory = self::$factories[$target] ?? null;
        if ($factory === null) {
            return;
        }

        self::register($alias, $factory, $overwrite);
    }

    /**
     * Возвращает список всех зарегистрированных имён инструментов.
     *
     * @return list<string>
     */
    public static function allNames(): array
    {
        self::ensureInitialized();

        /** @var list<string> $names */
        $names = array_keys(self::$factories);
        sort($names);

        return $names;
    }

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
        self::ensureInitialized();

        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $factory = self::$factories[$name] ?? null;
        if ($factory === null) {
            return null;
        }

        $tool = $factory($name, $agentCfg);
        if ($tool && $tool instanceof ATool) {
            $tool->setAgentCfg($agentCfg);
        }
        return $tool;
    }
}
