<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\producers;

use app\modules\neuron\classes\AProducer;
use app\modules\neuron\ConfigurationAgent;

/**
 * Фабрика конфигураций агентов по имени.
 *
 * Ищет файлы конфигураций в поддиректории "agents" через {@see DirPriority}
 * (приоритет директорий задаётся снаружи, например APP_START_DIR и APP_WORK_DIR)
 * и создаёт экземпляры {@see ConfigurationAgent} с учётом приоритета форматов.
 *
 * Приоритет форматов конфигурации:
 *  - в первую очередь используется PHP-файл "<name>.php";
 *  - если PHP-файл отсутствует, используется JSONC-файл "<name>.jsonc".
 */
class AgentProducer extends AProducer
{
    /**
     * @inheritDoc
     */
    public static function getStorageDirName(): string
    {
        return 'agents';
    }

    /**
     * @inheritDoc
     *
     * @return list<string>
     */
    protected function getExtensions(): array
    {
        return ['php', 'jsonc'];
    }

    /**
     * @inheritDoc
     */
    protected function createFromFile(string $path, string $name): ?ConfigurationAgent
    {
        $config = ConfigurationAgent::makeFromFile($path);

        if ($config instanceof ConfigurationAgent) {
            $config->agentName = $name;

            if ($config->enableChatHistory === false) {
                $config->resetChatHistory();
            }
        }

        return $config;
    }

    /**
     * Возвращает конфигурацию агента по его имени.
     *
     * @param string $name Имя агента (соответствует имени файла без расширения).
     *
     * @return ConfigurationAgent|null Экземпляр конфигурации агента или null.
     */
    public function get(string $name): ?ConfigurationAgent
    {
        $result = parent::get($name);

        return $result instanceof ConfigurationAgent ? $result : null;
    }
}
