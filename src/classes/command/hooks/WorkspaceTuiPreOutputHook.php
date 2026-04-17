<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\command\hooks;

use app\modules\neuron\classes\dto\tui\TuiPreHookDecisionDto;
use app\modules\neuron\classes\dto\tui\command\TuiCommandContextDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryDto;
use app\modules\neuron\classes\dto\tui\history\TuiHistoryEntryDto;
use app\modules\neuron\classes\dto\tui\view\blocks\NoticeBlockDto;
use app\modules\neuron\classes\dto\tui\view\blocks\TextBlockDto;
use app\modules\neuron\classes\tui\command\TuiCommandDispatcher;
use app\modules\neuron\classes\tui\command\UserInputParser;
use app\modules\neuron\classes\tui\command\handlers\ClearCommandHandler;
use app\modules\neuron\classes\tui\command\handlers\ExitCommandHandler;
use app\modules\neuron\classes\tui\command\handlers\HelpCommandHandler;
use app\modules\neuron\classes\tui\command\handlers\WorkspaceCommandHandler;
use app\modules\neuron\enums\tui\TuiNoticeKindEnum;
use app\modules\neuron\interfaces\tui\TuiPreOutputHookInterface;

/**
 * Pre-hook TUI для режима Workspace: парсер → dispatcher → handlers.
 *
 * Пример использования:
 *
 * ```php
 * $hook = new WorkspaceTuiPreOutputHook();
 * $decision = $hook->decide('/help');
 * ```
 */
final class WorkspaceTuiPreOutputHook implements TuiPreOutputHookInterface
{
    private UserInputParser $parser;
    private TuiCommandDispatcher $dispatcher;

    public function __construct()
    {
        $this->parser = new UserInputParser();
        $this->dispatcher = new TuiCommandDispatcher([
            new HelpCommandHandler(),
            new WorkspaceCommandHandler(),
            new ClearCommandHandler(),
            new ExitCommandHandler(),
        ]);
    }

    public function decide(string $originalInput): TuiPreHookDecisionDto
    {
        $ctx = new TuiCommandContextDto((string) getcwd(), new TuiHistoryDto());
        $parsed = $this->parser->parse($originalInput);

        $entries = [];
        $entries[] = TuiHistoryEntryDto::userInput($originalInput)
            ->setBlocks([new TextBlockDto('> ' . $originalInput)])
            ->setPlainText($originalInput);

        $decision = new TuiPreHookDecisionDto($originalInput);

        if ($parsed->isCommand()) {
            if ($parsed->getCommandName() === null) {
                $entries[] = TuiHistoryEntryDto::output('empty command')
                    ->setBlocks([new NoticeBlockDto(TuiNoticeKindEnum::Info, 'Введите /help')])
                    ->setPlainText('Введите /help');
                return $decision->setAppendEntries($entries);
            }

            $res = $this->dispatcher->dispatch($ctx, $parsed);
            if ($res->getAppendEntries() === []) {
                $entries[] = TuiHistoryEntryDto::output('unknown command')
                    ->setBlocks([new NoticeBlockDto(TuiNoticeKindEnum::Error, 'Неизвестная команда: /' . $parsed->getCommandName())])
                    ->setPlainText('Неизвестная команда');
            } else {
                foreach ($res->getAppendEntries() as $e) {
                    $entries[] = $e;
                }
            }

            $decision->setClearHistory($res->isClearHistory());
            $decision->setExit($res->isExit());
            return $decision->setAppendEntries($entries);
        }

        // Обычный текст: пока просто подсказываем про /help.
        $entries[] = TuiHistoryEntryDto::output('text')
            ->setBlocks([new NoticeBlockDto(TuiNoticeKindEnum::Info, 'Введите /help или команду, например /ws ls')])
            ->setPlainText('Введите /help');

        return $decision->setAppendEntries($entries);
    }
}
