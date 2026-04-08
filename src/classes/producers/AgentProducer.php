<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\producers;

use app\modules\neuron\classes\AProducer;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\traits\LoggerAwareTrait;

/**
 * Фабрика конфигураций агентов по имени.
 *
 * Ищет файлы конфигураций в поддиректории "agents" через {@see DirPriority}
 * (приоритет директорий задаётся снаружи через конструктор и класс DirPriority)
 * и создаёт экземпляры {@see ConfigurationAgent} с учётом приоритета форматов.
 *
 * Приоритет форматов конфигурации:
 *  - в первую очередь используется PHP-файл "<name>.php";
 *  - если PHP-файл отсутствует, используется JSONC-файл "<name>.jsonc".
 */
class AgentProducer extends AProducer
{
    use LoggerAwareTrait;

    public const STORAGE_DIR_NAME = 'agents';

    /**
     * @var list<string>
     */
    public const EXTENSIONS = ['php', 'jsonc'];

    /**
     * Имя агента по умолчанию.
     */
    protected static string $defaultAgentName = 'default';

    /**
     * Возвращает имя агента по умолчанию.
     */
    public static function getDefaultAgentName(): string
    {
        return static::$defaultAgentName;
    }

    /**
     * @inheritDoc
     */
    public static function getStorageDirName(): string
    {
        return self::STORAGE_DIR_NAME;
    }

    /**
     * @inheritDoc
     *
     * @return list<string>
     */
    protected function getExtensions(): array
    {
        return self::EXTENSIONS;
    }

    /**
     * @inheritDoc
     */
    protected function createFromFile(string $path, string $name): ?ConfigurationAgent
    {
        $config = ConfigurationAgent::makeFromFile($path, $this->getConfigurationApp());

        if ($config instanceof ConfigurationAgent) {
            $config->agentName = $name;

            if ($config->enableChatHistory === false) {
                $config->resetChatHistory();
            }
        }

        if (empty($config->logger)) {
            $config->setLogger($this->getLogger());
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
