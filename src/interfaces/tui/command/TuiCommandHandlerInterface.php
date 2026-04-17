<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces\tui\command;

use app\modules\neuron\classes\dto\tui\command\ParsedUserInputDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandContextDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandResultDto;

/**
 * Контракт обработчика пользовательских команд TUI.
 *
 * Пример использования:
 *
 * ```php
 * final class HelpHandler implements TuiCommandHandlerInterface { ... }
 * ```
 */
interface TuiCommandHandlerInterface
{
    public function getName(): string;

    public function handle(TuiCommandContextDto $ctx, ParsedUserInputDto $input): TuiCommandResultDto;
}
