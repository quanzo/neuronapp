<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\tui\command\handlers;

use app\modules\neuron\classes\dto\tui\command\ParsedUserInputDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandContextDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandResultDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryEntryDto;
use app\modules\neuron\classes\dto\tui\view\blocks\NoticeBlockDto;
use app\modules\neuron\enums\tui\TuiNoticeKindEnum;
use app\modules\neuron\interfaces\tui\command\TuiCommandHandlerInterface;

/**
 * Handler команды `/exit`.
 */
final class ExitCommandHandler implements TuiCommandHandlerInterface
{
    public function getName(): string
    {
        return 'exit';
    }

    public function handle(TuiCommandContextDto $ctx, ParsedUserInputDto $input): TuiCommandResultDto
    {
        $entry = TuiHistoryEntryDto::event('exit')
            ->setBlocks([new NoticeBlockDto(TuiNoticeKindEnum::Info, 'Выход...')])
            ->setPlainText('Выход...');

        return (new TuiCommandResultDto())
            ->setExit(true)
            ->setAppendEntries([$entry]);
    }
}
