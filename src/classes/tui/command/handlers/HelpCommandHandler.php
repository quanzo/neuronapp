<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\tui\command\handlers;

use app\modules\neuron\classes\dto\tui\command\ParsedUserInputDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandContextDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandResultDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryEntryDto;
use app\modules\neuron\classes\dto\tui\view\blocks\KeyHintsBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\ListBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\PanelBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\TableBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\TextBlockDto;
use app\modules\neuron\interfaces\tui\command\TuiCommandHandlerInterface;

/**
 * Handler команды `/help`.
 *
 * Пример использования:
 *
 * ```php
 * (new HelpCommandHandler())->handle($ctx, $input);
 * ```
 */
final class HelpCommandHandler implements TuiCommandHandlerInterface
{
    public function getName(): string
    {
        return 'help';
    }

    public function handle(TuiCommandContextDto $ctx, ParsedUserInputDto $input): TuiCommandResultDto
    {
        $asciiHelp = (string) (getenv('NEURON_TUI_HELP_ASCII') ?: '');
        $asciiMode = ($asciiHelp === '1' || strtolower($asciiHelp) === 'true');

        $rows = [
            ['/help', 'Справка по командам'],
            ['/ws ls', 'Показать рабочее пространство'],
            ['/clear', 'Очистить историю'],
            ['/exit', 'Выйти из TUI'],
        ];

        $listItems = [
            'Tab — переключить фокус (ввод/просмотр)',
            'Стрелки/PgUp/PgDn — прокрутка вывода (в режиме просмотра)',
            'Bracketed Paste — корректная многострочная вставка',
        ];
        $bullet = '•';
        $title = 'Neuron TUI';
        if ($asciiMode) {
            $bullet = '-';
            $title = 'Neuron TUI (ASCII help)';
            $listItems = [
                'Tab - switch focus (input/view)',
                'Arrows/PgUp/PgDn - scroll output (view mode)',
                'Bracketed Paste - multiline paste',
            ];
        }

        $panel = new PanelBlockDto($title, [
            new TextBlockDto('Команды:'),
            new TableBlockDto(['Command', 'Description'], $rows),
            (new ListBlockDto($listItems))->setBullet($bullet),
            new KeyHintsBlockDto(['Tab: focus', 'Enter: run', 'Ctrl+C: exit', '/help']),
        ]);

        $entry = TuiHistoryEntryDto::output('help')
            ->setBlocks([$panel])
            ->setPlainText('help');

        return (new TuiCommandResultDto())->setAppendEntries([$entry]);
    }
}
