<?php

namespace app\modules\neron\classes\command;

use app\modules\neron\interfaces\CommandInterface;

/**
 * Команда для отображения списка доступных команд.
 */
class HelpCommand implements CommandInterface
{
    /**
     * Выводит список команд.
     *
     * @param array $options Не используются
     * @return void
     */
    public function execute(array $options): void
    {
        echo "Доступные команды:\n";
        echo "  hello [--name=<имя>]  Приветствие\n";
        echo "  help                   Показать эту справку\n";
        echo "  interactive            Запустить интерактивный TUI\n";
    }
}