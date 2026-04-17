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
