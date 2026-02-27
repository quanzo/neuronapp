<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\producers;

use app\modules\neuron\ConfigurationAgent;

/**
 * Фабрика конфигураций агентов по имени.
 *
 * Отвечает за поиск файлов конфигураций в указанной директории (как правило,
 * это поддиректория "agents" в рабочей папке приложения) и создание
 * экземпляров {@see ConfigurationAgent} с учетом приоритета форматов.
 *
 * Приоритет форматов конфигурации:
 *  - в первую очередь используется PHP-файл "<name>.php";
 *  - если PHP-файл отсутствует, используется JSONC-файл "<name>.jsonc".
 */
class AgentProducer
{
    /**
     * Абсолютный путь к директории, где расположены файлы конфигураций агентов.
     */
    private string $directory;

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
     * Создает новый экземпляр производителя конфигураций агентов.
     *
     * @param string $directory Абсолютный путь к директории с файлами агентов.
     */
    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /**
     * Проверяет существование агента с указанным именем.
     *
     * Агент считается существующим, если в директории найден хотя бы один
     * из файлов конфигурации:
     *  - "<name>.php";
     *  - "<name>.jsonc".
     *
     * @param string $name Имя агента (без расширения файла).
     *
     * @return bool true, если конфигурационный файл агента найден, иначе false.
     */
    public function exist(string $name): bool
    {
        $phpFile = $this->buildPath($name, 'php');
        $jsoncFile = $this->buildPath($name, 'jsonc');

        return is_file($phpFile) || is_file($jsoncFile);
    }

    /**
     * Возвращает конфигурацию агента по его имени.
     *
     * При наличии одновременно файлов "<name>.php" и "<name>.jsonc"
     * приоритет отдается PHP-файлу.
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

        $phpFile = $this->buildPath($name, 'php');
        $jsoncFile = $this->buildPath($name, 'jsonc');

        $fileToLoad = null;

        if (is_file($phpFile)) {
            $fileToLoad = $phpFile;
        } elseif (is_file($jsoncFile)) {
            $fileToLoad = $jsoncFile;
        }

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
     * Формирует полный путь к файлу конфигурации агента.
     *
     * @param string $name      Имя агента (без расширения).
     * @param string $extension Расширение файла (без точки).
     *
     * @return string Абсолютный путь к файлу конфигурации.
     */
    private function buildPath(string $name, string $extension): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . $name
            . '.'
            . ltrim($extension, '.');
    }
}

