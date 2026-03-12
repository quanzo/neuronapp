<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\cmd\CmdDto;
use app\modules\neuron\classes\dto\cmd\FuncCmdDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see CmdDto}.
 *
 * CmdDto представляет абстрактную команду, задаваемую строкой с префиксом "@@".
 * Поддерживает разбор имени команды и списка позиционных параметров, а также
 * выбор специализированного DTO-класса по имени команды (например, FuncCmdDto).
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\dto\cmd\CmdDto}
 */
class CmdDtoTest extends TestCase
{
    /**
     * Строка вида "@@func(\"text\", 1)" разбирается в команду с именем "func",
     * двумя параметрами и возвращает специализированный класс {@see FuncCmdDto}.
     */
    public function testParsesSimpleFuncCommand(): void
    {
        $dto = CmdDto::fromString('@@func("text", 1)');

        $this->assertInstanceOf(FuncCmdDto::class, $dto);
        $this->assertSame('func', $dto->getName());
        $this->assertSame(['text', 1], $dto->getParams());
    }

    /**
     * Поддерживаются строки с одинарными/двойными кавычками, числа, true/false/null.
     */
    public function testParsesVariousScalarTypes(): void
    {
        $dto = CmdDto::fromString('@@func(\'text\', 1, 2.5, true, false, null)');

        $this->assertInstanceOf(FuncCmdDto::class, $dto);
        $this->assertSame('func', $dto->getName());
        $this->assertSame(
            ['text', 1, 2.5, true, false, null],
            $dto->getParams(),
        );
    }

    /**
     * Пробелы вокруг имени и аргументов не мешают корректному разбору.
     */
    public function testParsesWithWhitespaces(): void
    {
        $dto = CmdDto::fromString('  @@func ( "a" , 2 )  ');

        $this->assertInstanceOf(FuncCmdDto::class, $dto);
        $this->assertSame('func', $dto->getName());
        $this->assertSame(['a', 2], $dto->getParams());
    }

    /**
     * Команда без параметров (без скобок) приводит к пустому списку параметров.
     */
    public function testCommandWithoutParameters(): void
    {
        $dto = CmdDto::fromString('@@func');

        $this->assertInstanceOf(FuncCmdDto::class, $dto);
        $this->assertSame('func', $dto->getName());
        $this->assertSame([], $dto->getParams());
    }

    /**
     * Команда с пустыми скобками также даёт пустой список параметров.
     */
    public function testCommandWithEmptyParentheses(): void
    {
        $dto = CmdDto::fromString('@@func()');

        $this->assertInstanceOf(FuncCmdDto::class, $dto);
        $this->assertSame('func', $dto->getName());
        $this->assertSame([], $dto->getParams());
    }

    /**
     * Незарегистрированная команда возвращает базовый {@see CmdDto}.
     */
    public function testUnknownCommandReturnsBaseDto(): void
    {
        $dto = CmdDto::fromString('@@unknown(1, 2)');

        $this->assertInstanceOf(CmdDto::class, $dto);
        $this->assertNotInstanceOf(FuncCmdDto::class, $dto);
        $this->assertSame('unknown', $dto->getName());
        $this->assertSame([1, 2], $dto->getParams());
    }

    /**
     * Пустая строка или строка без "@@" не считаются командой.
     */
    public function testEmptyOrNoPrefixString(): void
    {
        $dtoEmpty = CmdDto::fromString('');
        $this->assertSame('', $dtoEmpty->getName());
        $this->assertSame([], $dtoEmpty->getParams());

        $dtoNoPrefix = CmdDto::fromString('func("text", 1)');
        $this->assertSame('', $dtoNoPrefix->getName());
        $this->assertSame([], $dtoNoPrefix->getParams());
    }

    /**
     * Сломанный синтаксис не приводит к выбросу исключения — возвращается базовый DTO.
     */
    public function testBrokenSyntaxDoesNotThrow(): void
    {
        $dto = CmdDto::fromString('@@func("text, 1)');

        $this->assertInstanceOf(FuncCmdDto::class, $dto);
        $this->assertSame('func', $dto->getName());
        $this->assertIsArray($dto->getParams());
    }

    /**
     * Строка "@@" без имени команды приводит к пустому имени и списку параметров.
     */
    public function testOnlyPrefixWithoutName(): void
    {
        $dto = CmdDto::fromString('@@');

        $this->assertSame('', $dto->getName());
        $this->assertSame([], $dto->getParams());
    }

    /**
     * Разные варианты пробелов в сигнатуре команды дают один и тот же объект и сигнатуру.
     */
    public function testSignaturesWithAndWithoutSpacesAreEquivalent(): void
    {
        $dtoWithSpace    = CmdDto::fromString('@@func("text", 1)');
        $dtoWithoutSpace = CmdDto::fromString('@@func("text",1)');

        $this->assertSame($dtoWithSpace->getName(), $dtoWithoutSpace->getName());
        $this->assertSame($dtoWithSpace->getParams(), $dtoWithoutSpace->getParams());
        $this->assertSame($dtoWithSpace->toSignature(), $dtoWithoutSpace->toSignature());
        $this->assertSame('@@func("text", 1)', $dtoWithSpace->toSignature());
    }

    /**
     * replaceSignatureInText() находит и вариант без пробела после запятой.
     */
    public function testReplaceSignatureHandlesSpacingVariants(): void
    {
        $dto = CmdDto::fromString('@@func("text", 1)');

        $body = '@@func("text",1) and @@func("text", 1)';

        $replaced = $dto->replaceSignatureInText($body, 'REPLACED');

        $this->assertSame('REPLACED and @@func("text", 1)', $replaced);
    }

    /**
     * replaceAllInText() удаляет все команды, включая варианты форматирования пробелов.
     */
    public function testReplaceAllInTextRemovesAllCommands(): void
    {
        $bodySingleParam = 'A @@one("x") B';
        $this->assertSame('A  B', CmdDto::replaceAllInText($bodySingleParam));

        $bodyTwoParams = 'X @@two("a",1) Y';
        $this->assertSame('X  Y', CmdDto::replaceAllInText($bodyTwoParams));

        $bodyManyParams = <<<'TXT'
Префикс @@func("text",1,true,false,null,2.5) середина @@FUNC("text", 1, true , false , null , 2.5) суффикс @@unknown(2,3)
TXT;

        $resultMany = CmdDto::replaceAllInText($bodyManyParams);

        $this->assertSame("Префикс  середина  суффикс ", $resultMany);
    }
}
