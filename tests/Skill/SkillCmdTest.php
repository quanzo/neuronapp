<?php

declare(strict_types=1);

namespace Tests\Skill;

use app\modules\neuron\classes\dto\cmd\CmdDto;
use app\modules\neuron\classes\skill\Skill;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для получения списка команд из тела Skill.
 */
class SkillCmdTest extends TestCase
{
    /**
     * Навык без команд возвращает пустой список CmdDto.
     */
    public function testGetCmdListEmpty(): void
    {
        $skill = new Skill("Just text without commands");

        $this->assertSame([], $skill->getCmdList());
    }

    /**
     * Навык с несколькими командами возвращает их в порядке появления, при этом
     * конструкции без префикса "@@" не считаются командами.
     */
    public function testGetCmdListWithCommands(): void
    {
        $body = <<<'TXT'
@@func("text", 1)
text@func(1, 2) text
@@unknown(2,3) and @@FUNC("UP", 10)
TXT;

        $skill = new Skill($body, 'test');

        $cmds = $skill->getCmdList();

        $this->assertCount(3, $cmds);
        $this->assertSame('func', $cmds[0]->getName());
        $this->assertSame(['text', 1], $cmds[0]->getParams());

        $this->assertSame('unknown', $cmds[1]->getName());
        $this->assertSame([2, 3], $cmds[1]->getParams());

        $this->assertSame('FUNC', $cmds[2]->getName());
        $this->assertSame(['UP', 10], $cmds[2]->getParams());
    }
}
