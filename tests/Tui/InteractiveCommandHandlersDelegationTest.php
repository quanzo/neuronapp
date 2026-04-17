<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\command\InteractiveCommand;
use app\modules\neuron\classes\dto\tui\command\ParsedUserInputDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandContextDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandResultDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryDto;
use app\modules\neuron\classes\tui\command\TuiCommandDispatcher;
use app\modules\neuron\interfaces\tui\command\TuiCommandHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты делегирования регистрации handlers в {@see InteractiveCommand}.
 *
 * Критично проверить, что `InteractiveCommand::addHandler/removeHandler*` корректно проксируют вызовы
 * во внутренний {@see TuiCommandDispatcher}, и что поведение overwrite совпадает с требованиями.
 */
final class InteractiveCommandHandlersDelegationTest extends TestCase
{
    /**
     * addHandler регистрирует handler во внутреннем dispatcher (happy path).
     */
    public function testAddHandlerRegistersInInternalDispatcher(): void
    {
        $cmd = new InteractiveCommand();
        $cmd->addHandler($this->handler('help', (new TuiCommandResultDto())->setExit(true)));

        $dispatcher = $this->getInternalDispatcher($cmd);
        $res = $dispatcher->dispatch($this->ctx(), $this->parsed('help'));
        $this->assertTrue($res->isExit());
    }

    /**
     * Поведение overwrite: последний handler с тем же именем должен побеждать.
     */
    public function testAddHandlerOverwritesByName(): void
    {
        $cmd = new InteractiveCommand();
        $cmd->addHandler($this->handler('exit', (new TuiCommandResultDto())->setExit(false)));
        $cmd->addHandler($this->handler('exit', (new TuiCommandResultDto())->setExit(true)));

        $dispatcher = $this->getInternalDispatcher($cmd);
        $res = $dispatcher->dispatch($this->ctx(), $this->parsed('exit'));
        $this->assertTrue($res->isExit());
    }

    /**
     * removeHandlerByName возвращает false, если такого handler нет (граничный случай).
     *
     * @param string $name
     */
    #[DataProvider('nonExistingNamesProvider')]
    public function testRemoveHandlerByNameReturnsFalseWhenMissing(string $name): void
    {
        $cmd = new InteractiveCommand();
        $this->assertFalse($cmd->removeHandlerByName($name));
    }

    /**
     * removeHandlerByName удаляет handler и возвращает true, если handler был зарегистрирован.
     */
    public function testRemoveHandlerByNameRemovesAndReturnsTrue(): void
    {
        $cmd = new InteractiveCommand();
        $cmd->addHandler($this->handler('help', (new TuiCommandResultDto())->setExit(true)));

        $this->assertTrue($cmd->removeHandlerByName('help'));

        $dispatcher = $this->getInternalDispatcher($cmd);
        $res = $dispatcher->dispatch($this->ctx(), $this->parsed('help'));
        $this->assertFalse($res->isExit());
    }

    /**
     * removeHandler(handler) удаляет handler по имени экземпляра.
     */
    public function testRemoveHandlerRemovesByInstanceName(): void
    {
        $cmd = new InteractiveCommand();
        $handler = $this->handler('help', (new TuiCommandResultDto())->setExit(true));
        $cmd->addHandler($handler);

        $this->assertTrue($cmd->removeHandler($handler));

        $dispatcher = $this->getInternalDispatcher($cmd);
        $res = $dispatcher->dispatch($this->ctx(), $this->parsed('help'));
        $this->assertFalse($res->isExit());
    }

    /**
     * Минимальный набор заведомо «не существующих» имён (>= 10) для проверки реакции на отсутствие.
     *
     * @return list<array{0:string}>
     */
    public static function nonExistingNamesProvider(): array
    {
        return [
            [''],
            [' '],
            ['unknown'],
            ['help '],
            [' help'],
            ['ws'],
            ['clear'],
            ['exit'],
            ['__'],
            ['123'],
        ];
    }

    /**
     * Получает внутренний dispatcher из {@see InteractiveCommand} через reflection.
     *
     * Это нужно для теста делегирования: публичного getter для dispatcher у команды быть не должно.
     */
    private function getInternalDispatcher(InteractiveCommand $cmd): TuiCommandDispatcher
    {
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('getDispatcher');
        $method->setAccessible(true);
        /** @var TuiCommandDispatcher $dispatcher */
        $dispatcher = $method->invoke($cmd);
        return $dispatcher;
    }

    /**
     * Хелпер: контекст выполнения.
     */
    private function ctx(): TuiCommandContextDto
    {
        return new TuiCommandContextDto('/tmp', new TuiHistoryDto());
    }

    /**
     * Хелпер: DTO команды.
     */
    private function parsed(string $name): ParsedUserInputDto
    {
        return new ParsedUserInputDto(raw: '/' . $name, isCommand: true, commandName: $name, args: []);
    }

    /**
     * Хелпер: stub handler.
     */
    private function handler(string $name, TuiCommandResultDto $result): TuiCommandHandlerInterface
    {
        return new class ($name, $result) implements TuiCommandHandlerInterface {
            public function __construct(private readonly string $name, private readonly TuiCommandResultDto $result)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function handle(TuiCommandContextDto $ctx, ParsedUserInputDto $input): TuiCommandResultDto
            {
                return $this->result;
            }
        };
    }
}
