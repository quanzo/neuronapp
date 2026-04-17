<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\dto\tui\command\ParsedUserInputDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandContextDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandResultDto;
use app\modules\neuron\classes\tui\command\TuiCommandDispatcher;
use app\modules\neuron\interfaces\tui\command\TuiCommandHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see TuiCommandDispatcher}.
 *
 * Проверяем регистрацию/удаление handlers и поведение overwrite по имени.
 */
final class TuiCommandDispatcherTest extends TestCase
{
    /**
     * Добавление handler'а делает его доступным по имени (happy path).
     */
    public function testAddHandlerRegistersHandler(): void
    {
        $dispatcher = new TuiCommandDispatcher([]);
        $handler = $this->handler('help', new TuiCommandResultDto());
        $dispatcher->addHandler($handler);

        $res = $dispatcher->dispatch($this->ctx(), $this->cmd('help'));
        $this->assertInstanceOf(TuiCommandResultDto::class, $res);
    }

    /**
     * Если handler не найден — возвращается пустой результат (без append entries).
     */
    public function testDispatchUnknownReturnsEmptyResult(): void
    {
        $dispatcher = new TuiCommandDispatcher([]);
        $res = $dispatcher->dispatch($this->ctx(), $this->cmd('unknown'));
        $this->assertSame([], $res->getAppendEntries());
    }

    /**
     * Поведение overwrite: добавление второго handler'а с тем же именем должно перезаписать первый.
     */
    public function testAddHandlerOverwritesByName(): void
    {
        $dispatcher = new TuiCommandDispatcher([]);
        $res1 = (new TuiCommandResultDto())->setAppendEntries([]);
        $res2 = (new TuiCommandResultDto())->setExit(true);

        $dispatcher->addHandler($this->handler('exit', $res1));
        $dispatcher->addHandler($this->handler('exit', $res2));

        $res = $dispatcher->dispatch($this->ctx(), $this->cmd('exit'));
        $this->assertTrue($res->isExit());
    }

    /**
     * removeHandlerByName возвращает false, если handler не зарегистрирован (граничный случай).
     *
     * @param string $name
     */
    #[DataProvider('nonExistingNamesProvider')]
    public function testRemoveHandlerByNameReturnsFalseWhenMissing(string $name): void
    {
        $dispatcher = new TuiCommandDispatcher([]);
        $this->assertFalse($dispatcher->removeHandlerByName($name));
    }

    /**
     * removeHandlerByName удаляет handler и возвращает true, если он был зарегистрирован.
     */
    public function testRemoveHandlerByNameRemovesAndReturnsTrue(): void
    {
        $dispatcher = new TuiCommandDispatcher([]);
        $dispatcher->addHandler($this->handler('help', new TuiCommandResultDto()));

        $this->assertTrue($dispatcher->removeHandlerByName('help'));

        $res = $dispatcher->dispatch($this->ctx(), $this->cmd('help'));
        $this->assertSame([], $res->getAppendEntries());
    }

    /**
     * removeHandler(handler) удаляет по имени handler'а.
     */
    public function testRemoveHandlerRemovesByInstanceName(): void
    {
        $dispatcher = new TuiCommandDispatcher([]);
        $handler = $this->handler('help', (new TuiCommandResultDto())->setExit(true));
        $dispatcher->addHandler($handler);

        $this->assertTrue($dispatcher->removeHandler($handler));

        $res = $dispatcher->dispatch($this->ctx(), $this->cmd('help'));
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
     * Хелпер: контекст выполнения.
     */
    private function ctx(): TuiCommandContextDto
    {
        return new TuiCommandContextDto('/tmp', new \app\modules\neuron\classes\dto\tui\history\TuiHistoryDto());
    }

    /**
     * Хелпер: DTO команды.
     */
    private function cmd(string $name): ParsedUserInputDto
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
