<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\dto\cmd\CmdDto;
use app\modules\neuron\classes\dto\cmd\FuncCmdDto;
use app\modules\neuron\helpers\FileContextHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты выборки и замены команд с префиксом "@@" в {@see FileContextHelper} и {@see CmdDto}.
 */
class FileContextHelperCmdTest extends TestCase
{
    /**
     * Пустой текст — пустой список команд.
     */
    public function testExtractCmdFromEmptyBodyReturnsEmpty(): void
    {
        $this->assertSame([], FileContextHelper::extractCmdFromBody(''));
    }

    /**
     * В тексте находятся команды с префиксом "@@" и корректно разбираются.
     */
    public function testExtractCmdFromBodyFindsCommands(): void
    {
        $body = <<<'TXT'
Преамбула
@@func("text", 1)
Ещё текст @@unknown(2, 3) и @@FUNC("UP", 10)
TXT;

        $cmds = FileContextHelper::extractCmdFromBody($body);

        $this->assertCount(3, $cmds);

        $this->assertInstanceOf(FuncCmdDto::class, $cmds[0]);
        $this->assertSame('func', $cmds[0]->getName());
        $this->assertSame(['text', 1], $cmds[0]->getParams());

        $this->assertInstanceOf(CmdDto::class, $cmds[1]);
        $this->assertSame('unknown', $cmds[1]->getName());
        $this->assertSame([2, 3], $cmds[1]->getParams());

        $this->assertInstanceOf(FuncCmdDto::class, $cmds[2]);
        $this->assertSame('FUNC', $cmds[2]->getName());
        $this->assertSame(['UP', 10], $cmds[2]->getParams());
    }

    /**
     * Сигнатура может быть единственной в строке или стоять в начале строки,
     * но одиночный символ '@' не считается префиксом команды.
     */
    public function testExtractCmdFromBodyRespectsPrefixAndPosition(): void
    {
        $only = "@@func(\"x\", 1)\n";
        $this->assertCount(1, FileContextHelper::extractCmdFromBody($only));

        $atStart = "@@func(\"x\", 1) trailing text\n";
        $this->assertCount(1, FileContextHelper::extractCmdFromBody($atStart));

        $noCommand = "text@func(1, 2) text\n";
        $this->assertSame([], FileContextHelper::extractCmdFromBody($noCommand));
    }

    /**
     * Метод replaceSignatureInText() заменяет только точное вхождение сигнатуры
     * с учётом регистра.
     */
    public function testReplaceSignatureInTextIsCaseSensitive(): void
    {
        $dto = CmdDto::fromString('@@func("text", 1)');

        $body = '@@func("text", 1) and @@FUNC("text", 1)';

        $replaced = $dto->replaceSignatureInText($body, 'REPLACED');

        $this->assertSame('REPLACED and @@FUNC("text", 1)', $replaced);
    }
}
