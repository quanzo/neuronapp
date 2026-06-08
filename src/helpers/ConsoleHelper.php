<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\console\ConsoleServiceMessageDto;
use app\modules\neuron\classes\dto\console\OutputDto;
use app\modules\neuron\enums\ConsoleServiceMessageLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Вспомогательные методы для унифицированного вывода консольных LLM-команд.
 */
class ConsoleHelper
{
    /** @var list<string> */
    public const FORMATS = ['md', 'txt', 'json'];

    /**
     * Нормализует формат вывода или возвращает OutputDto с ошибкой при неверном значении.
     *
     * @param string|null $format  Сырое значение опции --format.
     * @param string      $default Формат по умолчанию.
     *
     * @return string|OutputDto Нормализованный формат или DTO с ошибкой.
     */
    public static function resolveFormat(?string $format, string $default = 'md'): string|OutputDto
    {
        if ($format === null || $format === '') {
            return $default;
        }
        if (!in_array($format, self::FORMATS, true)) {
            return OutputDto::fromError('Формат вывода задан не корректно.');
        }

        return $format;
    }

    /**
     * Форматирует OutputDto для stdout в соответствии с форматом.
     *
     * @param OutputDto $dto    DTO результата команды.
     * @param string    $format Формат: md, txt или json.
     */
    public static function formatOut(OutputDto $dto, string $format = 'md'): string
    {
        return match ($format) {
            'json' => JsonHelper::encodeThrow($dto->toArray()),
            default => self::formatText($dto),
        };
    }

    /**
     * Записывает результат в stdout и возвращает exit code команды.
     *
     * @param OutputInterface $output Консольный вывод.
     * @param OutputDto       $dto    DTO результата.
     * @param string          $format Формат: md, txt или json.
     */
    public static function writeResult(OutputInterface $output, OutputDto $dto, string $format): int
    {
        $output->writeln(self::formatOut($dto, $format));

        if ($dto->isError()) {
            return Command::FAILURE;
        }

        $orchestrator = $dto->getOrchestrator();
        if ($orchestrator !== null && !$orchestrator->isSuccess()) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Форматирует DTO в текстовом виде (md / txt).
     */
    private static function formatText(OutputDto $dto): string
    {
        $parts = [];

        foreach ($dto->getServiceMessages()->getAll() as $message) {
            $parts[] = self::formatServiceMessage($message);
        }

        if ($parts !== []) {
            $parts[] = '';
        }

        if ($dto->isError()) {
            $parts[] = '<error>' . $dto->getErrorMessage() . '</error>';
        } else {
            $parts[] = $dto->getResponse();
        }

        $parts[] = '';
        $parts[] = 'sessionKey=' . $dto->getSessionKey();

        $timing = $dto->getExecutionTiming();
        if ($timing !== null) {
            array_push($parts, ...$timing->formatTextLines());
        }

        return implode(PHP_EOL, $parts) . PHP_EOL;
    }

    /**
     * Рендерит одно сервисное сообщение для md/txt.
     */
    private static function formatServiceMessage(ConsoleServiceMessageDto $message): string
    {
        $text = $message->getText();

        return match ($message->getLevel()) {
            ConsoleServiceMessageLevel::Info => '<info>' . $text . '</info>',
            ConsoleServiceMessageLevel::Comment => '<comment>' . $text . '</comment>',
            ConsoleServiceMessageLevel::Plain => $text,
        };
    }
}
