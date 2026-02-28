<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\producers;

use app\modules\neuron\classes\dir\DirPriority;
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
class AgentProducer
{
    private const AGENTS_SUBDIR = 'agents';

    /**
     * Приоритетный список директорий для поиска файлов агентов (в каждой ищется поддиректория agents).
     */
    private DirPriority $dirPriority;

    /**
     * Внутренний кеш созданных конфигураций агентов.
     *
     * Ключом выступает имя агента, значением — экземпляр {@see ConfigurationAgent}
     * или null, если конфигурацию не удалось создать.
     *
     * @var array<string, ConfigurationAgent|null>
     */
    private array $cache = [];

    /**
     * Создаёт новый экземпляр производителя конфигураций агентов.
     *
     * @param DirPriority $dirPriority Приоритетный список директорий (в каждой ожидается поддиректория agents).
     */
    public function __construct(DirPriority $dirPriority)
    {
        $this->dirPriority = $dirPriority;
    }

    /**
     * Проверяет существование агента с указанным именем.
     *
     * Агент считается существующим, если в приоритетных директориях найден хотя бы один
     * из файлов конфигурации: "<name>.php" или "<name>.jsonc" в поддиректории agents.
     *
     * @param string $name Имя агента (без расширения файла).
     *
     * @return bool true, если конфигурационный файл агента найден, иначе false.
     */
    public function exist(string $name): bool
    {
        return $this->resolveAgentFile($name) !== null;
    }

    /**
     * Возвращает конфигурацию агента по его имени.
     *
     * При наличии одновременно файлов "<name>.php" и "<name>.jsonc"
     * приоритет отдается PHP-файлу (порядок расширений в resolveFile).
     *
     * @param string $name Имя агента (соответствует имени файла без расширения).
     *
     * @return ConfigurationAgent|null Экземпляр конфигурации агента или null,
     *                                 если подходящий файл не найден или не удалось
     *                                 корректно создать конфигурацию.
     */
    public function get(string $name): ?ConfigurationAgent
    {
        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }

        $fileToLoad = $this->resolveAgentFile($name);

        if ($fileToLoad === null) {
            $this->cache[$name] = null;

            return null;
        }

        $config = ConfigurationAgent::makeFromFile($fileToLoad);

        if ($config instanceof ConfigurationAgent) {
            // Проставляем имя агента в конфигурации для формирования ключа сессии.
            $config->agentName = $name;

            // При отключенной истории (in-memory) очищаем историю при каждом получении.
            if ($config->enableChatHistory === false) {
                $config->resetChatHistory();
            }
        }

        $this->cache[$name] = $config;

        return $config;
    }

    /**
     * Ищет файл конфигурации агента в приоритетных директориях (поддиректория agents).
     *
     * @return string|null Абсолютный путь к файлу или null.
     */
    private function resolveAgentFile(string $name): ?string
    {
        $relPath = self::AGENTS_SUBDIR . '/' . $name;

        return $this->dirPriority->resolveFile($relPath, ['php', 'jsonc']);
    }
}

