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
    public const STORAGE_DIR_NAME = 'skills';

    /**
     * @var list<string>
     */
    public const EXTENSIONS = ['txt', 'md'];

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
    protected function createFromFile(string $path, string $name): ?Skill
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return new Skill($contents, $name, $this->getConfigurationApp());
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
