<?php

declare(strict_types=1);

namespace Tests\Producers;

use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\producers\SkillProducer;
use app\modules\neuron\classes\skill\Skill;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see SkillProducer}.
 *
 * SkillProducer — фабрика навыков (Skill) по имени.
 * Ищет файлы в поддиректории «skills/» через DirPriority.
 * Поддерживаемые расширения: .txt (приоритет), .md.
 * Результат кешируется по имени навыка.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\producers\SkillProducer}
 */
class SkillProducerTest extends TestCase
{
    /** @var string Временная директория с подкаталогом skills/. */
    private string $tmpDir;

    /**
     * Создаёт временную директорию с подкаталогом skills/.
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_skillprod_' . uniqid();
        mkdir($this->tmpDir . '/skills', 0777, true);
    }

    /**
     * Удаляет временную директорию.
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /**
     * Рекурсивное удаление директории.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Имя поддиректории хранения — «skills».
     */
    public function testGetStorageDirName(): void
    {
        $this->assertSame('skills', SkillProducer::getStorageDirName());
    }

    /**
     * Несуществующий навык — exist() возвращает false.
     */
    public function testExistReturnsFalseForMissing(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new SkillProducer($dp);
        $this->assertFalse($producer->exist('nonexistent'));
    }

    /**
     * Файл .txt в skills/ — exist() возвращает true.
     */
    public function testExistReturnsTrueForTxt(): void
    {
        file_put_contents($this->tmpDir . '/skills/search.txt', 'Search for $query');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new SkillProducer($dp);
        $this->assertTrue($producer->exist('search'));
    }

    /**
     * Файл .md в skills/ — exist() возвращает true.
     */
    public function testExistReturnsTrueForMd(): void
    {
        file_put_contents($this->tmpDir . '/skills/search.md', 'Search for $query');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new SkillProducer($dp);
        $this->assertTrue($producer->exist('search'));
    }

    /**
     * Несуществующий навык — get() возвращает null.
     */
    public function testGetReturnsNullForMissing(): void
    {
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new SkillProducer($dp);
        $this->assertNull($producer->get('missing'));
    }

    /**
     * Существующий .txt-файл — get() возвращает объект Skill
     * с правильным именем.
     */
    public function testGetReturnsSkill(): void
    {
        file_put_contents($this->tmpDir . '/skills/translate.txt', 'Translate $text to $lang');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new SkillProducer($dp);

        $skill = $producer->get('translate');
        $this->assertInstanceOf(Skill::class, $skill);
        $this->assertSame('translate', $skill->getName());
    }

    /**
     * Повторный вызов get() возвращает тот же объект из кеша.
     */
    public function testGetCachesResult(): void
    {
        file_put_contents($this->tmpDir . '/skills/cached.txt', 'body');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new SkillProducer($dp);

        $first = $producer->get('cached');
        $second = $producer->get('cached');
        $this->assertSame($first, $second);
    }

    /**
     * При наличии и .txt, и .md — файл .txt имеет приоритет.
     */
    public function testTxtPriorityOverMd(): void
    {
        file_put_contents($this->tmpDir . '/skills/dual.txt', 'TXT content');
        file_put_contents($this->tmpDir . '/skills/dual.md', 'MD content');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new SkillProducer($dp);

        $skill = $producer->get('dual');
        $this->assertSame('TXT content', $skill->getSkill());
    }

    /**
     * Навык в подкаталоге (sub/deep) — имя включает путь к подкаталогу.
     */
    public function testGetWithSubdirectory(): void
    {
        mkdir($this->tmpDir . '/skills/sub', 0777, true);
        file_put_contents($this->tmpDir . '/skills/sub/deep.txt', 'Deep skill');
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new SkillProducer($dp);

        $skill = $producer->get('sub/deep');
        $this->assertInstanceOf(Skill::class, $skill);
        $this->assertSame('sub/deep', $skill->getName());
    }

    /**
     * Файл навыка с блоком опций — опции корректно парсятся.
     */
    public function testGetWithOptionsInFile(): void
    {
        $content = "---\ndescription: Translate text\nparams: {\"text\": \"string\"}\n---\nTranslate \$text";
        file_put_contents($this->tmpDir . '/skills/opts.txt', $content);
        $dp = new DirPriority([$this->tmpDir]);
        $producer = new SkillProducer($dp);

        $skill = $producer->get('opts');
        $this->assertSame('Translate text', $skill->getOptions()['description']);
    }

    /**
     * Навык существует в обеих директориях — берётся из первой (приоритетной).
     */
    public function testPriorityAcrossDirectories(): void
    {
        $dir2 = $this->tmpDir . '/dir2';
        mkdir($dir2 . '/skills', 0777, true);
        file_put_contents($this->tmpDir . '/skills/shared.txt', 'From primary');
        file_put_contents($dir2 . '/skills/shared.txt', 'From secondary');

        $dp = new DirPriority([$this->tmpDir, $dir2]);
        $producer = new SkillProducer($dp);

        $skill = $producer->get('shared');
        $this->assertSame('From primary', $skill->getSkill());
    }
}
