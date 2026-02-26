<?php

namespace MyApp;

use app\modules\neron\interfaces\CommandInterface;
use app\modules\neron\classes\command\HelpCommand;

/**
 * Основной класс приложения.
 * Регистрирует команды и запускает их в зависимости от аргументов командной строки.
 */
class Application
{
    /**
     * @var array<string, string> Карта соответствия «имя команды → класс»
     */
    private array $commands = [];

    /**
     * Регистрирует команду.
     *
     * @param string $name Имя команды (без префиксов)
     * @param string $className Полное имя класса, реализующего CommandInterface
     */
    public function registerCommand(string $name, string $className): void
    {
        $this->commands[$name] = $className;
    }

    /**
     * Запускает приложение с переданными аргументами командной строки.
     *
     * @param array $argv Массив аргументов (обычно $argv из глобальной области)
     */
    public function run(array $argv): void
    {
        // Отбрасываем имя скрипта
        array_shift($argv);

        // Если команда не указана – показываем справку
        if (empty($argv)) {
            $this->showHelp();
            return;
        }

        $commandName = array_shift($argv);
        $options = $this->parseOptions($argv);

        // Проверяем, зарегистрирована ли команда
        if (!isset($this->commands[$commandName])) {
            echo "Неизвестная команда: {$commandName}\n";
            $this->showHelp();
            return;
        }

        $className = $this->commands[$commandName];
        if (!class_exists($className)) {
            echo "Класс команды не найден: {$className}\n";
            return;
        }

        $command = new $className();
        if (!$command instanceof CommandInterface) {
            echo "Класс команды должен реализовывать интерфейс CommandInterface\n";
            return;
        }

        $command->execute($options);
    }

    /**
     * Разбирает аргументы, извлекая опции вида --ключ=значение или --флаг.
     *
     * @param array $args Аргументы после имени команды
     * @return array Ассоциативный массив опций
     */
    private function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                // Убираем префикс --
                $arg = substr($arg, 2);

                if (str_contains($arg, '=')) {
                    // Опция со значением
                    [$key, $value] = explode('=', $arg, 2);
                    $options[$key] = $value;
                } else {
                    // Опция-флаг (без значения)
                    $options[$arg] = true;
                }
            }
            // Аргументы без префикса -- игнорируются (можно расширить при необходимости)
        }
        return $options;
    }

    /**
     * Показывает справку (вызывает команду HelpCommand).
     */
    private function showHelp(): void
    {
        $help = new HelpCommand();
        $help->execute([]);
    }
}