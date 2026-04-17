<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\command;

/**
 * DTO распарсенного пользовательского ввода для TUI.
 *
 * Пример использования:
 *
 * ```php
 * $dto = new ParsedUserInputDto('/help', true, 'help', []);
 * ```
 */
final class ParsedUserInputDto
{
    /**
     * @param string $raw
     * @param bool $isCommand
     * @param string|null $commandName
     * @param list<string> $args
     */
    public function __construct(
        private readonly string $raw,
        private readonly bool $isCommand,
        private readonly ?string $commandName,
        private readonly array $args,
    ) {
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function isCommand(): bool
    {
        return $this->isCommand;
    }

    public function getCommandName(): ?string
    {
        return $this->commandName;
    }

    /**
     * @return list<string>
     */
    public function getArgs(): array
    {
        return $this->args;
    }
}
