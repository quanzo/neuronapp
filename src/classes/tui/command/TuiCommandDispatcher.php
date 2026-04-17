<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\tui\command;

use app\modules\neuron\classes\dto\tui\command\ParsedUserInputDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandContextDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandResultDto;
use app\modules\neuron\interfaces\tui\command\TuiCommandHandlerInterface;

/**
 * Диспетчер TUI-команд: выбирает handler по имени.
 *
 * Пример использования:
 *
 * ```php
 * $dispatcher = new TuiCommandDispatcher([$helpHandler]);
 * $res = $dispatcher->dispatch($ctx, $input);
 * ```
 */
final class TuiCommandDispatcher
{
    /** @var array<string, TuiCommandHandlerInterface> */
    private array $handlersByName = [];

    /**
     * @param list<TuiCommandHandlerInterface> $handlers
     */
    public function __construct(array $handlers)
    {
        foreach ($handlers as $handler) {
            $this->handlersByName[$handler->getName()] = $handler;
        }
    }

    /**
     * Регистрирует handler в диспетчере.
     *
     * Если handler с таким именем уже был зарегистрирован, он будет перезаписан (overwrite).
     *
     * @param TuiCommandHandlerInterface $handler
     * @return self
     */
    public function addHandler(TuiCommandHandlerInterface $handler): self
    {
        $this->handlersByName[$handler->getName()] = $handler;
        return $this;
    }

    /**
     * Удаляет handler по имени команды.
     *
     * @param string $name Имя команды (без `/`)
     * @return bool true если handler был найден и удалён, иначе false
     */
    public function removeHandlerByName(string $name): bool
    {
        if (!array_key_exists($name, $this->handlersByName)) {
            return false;
        }

        unset($this->handlersByName[$name]);
        return true;
    }

    /**
     * Удаляет handler по экземпляру.
     *
     * Удаление выполняется по имени handler'а (`$handler->getName()`).
     *
     * @param TuiCommandHandlerInterface $handler
     * @return bool true если handler был найден и удалён, иначе false
     */
    public function removeHandler(TuiCommandHandlerInterface $handler): bool
    {
        return $this->removeHandlerByName($handler->getName());
    }

    /**
     * Диспетчеризует команду на handler по имени.
     *
     * Если handler не найден — возвращает «пустой» результат (без append entries).
     * Вызывающая сторона решает, как реагировать на неизвестные команды.
     *
     * @param TuiCommandContextDto $ctx Контекст выполнения (cwd и т.д.)
     * @param ParsedUserInputDto $input Результат парсинга пользовательского ввода
     * @return TuiCommandResultDto Результат handler’а или пустой результат, если handler не найден
     */
    public function dispatch(TuiCommandContextDto $ctx, ParsedUserInputDto $input): TuiCommandResultDto
    {
        $name = (string) $input->getCommandName();
        $handler = $this->handlersByName[$name] ?? null;
        if ($handler === null) {
            return (new TuiCommandResultDto());
        }

        return $handler->handle($ctx, $input);
    }
}
