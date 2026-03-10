<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\producers;

use app\modules\neuron\classes\AProducer;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\classes\dir\DirPriority;

/**
 * Фабрика навыков (Skill) по имени.
 *
 * Ищет файлы в поддиректории "skills" через {@see DirPriority}
 * и создаёт экземпляры {@see Skill} из содержимого файла.
 * Поддерживаются расширения: .txt, .md.
 */
class SkillProducer extends AProducer
{
    /**
     * Глобальная конфигурация приложения.
     */
    private ConfigurationApp $configApp;

    /**
     * @param ConfigurationApp $configApp  Экземпляр конфигурации приложения.
     */
    public function __construct(DirPriority $dirPriority, ConfigurationApp $configApp)
    {
        parent::__construct($dirPriority);
        $this->configApp = $configApp;
    }
    /**
     * @inheritDoc
     */
    public static function getStorageDirName(): string
    {
        return 'skills';
    }

    /**
     * @inheritDoc
     *
     * @return list<string>
     */
    protected function getExtensions(): array
    {
        return ['txt', 'md'];
    }

    /**
     * @inheritDoc
     */
    protected function createFromFile(string $path, string $name): ?Skill
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return new Skill($contents, $name, $this->configApp);
    }

    /**
     * Возвращает навык по имени.
     *
     * @param string $name Имя навыка (соответствует имени файла без расширения).
     *
     * @return Skill|null Экземпляр навыка или null.
     */
    public function get(string $name): ?Skill
    {
        $result = parent::get($name);

        return $result instanceof Skill ? $result : null;
    }
}
