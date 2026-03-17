<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\attachments\AttachmentDto;
use app\modules\neuron\classes\dto\cmd\AgentCmdDto;
use app\modules\neuron\classes\dto\cmd\CmdDto;

/**
 * Хелпер для анализа текста контекста: извлечения @-ссылок на файлы и @@-команд.
 *
 * Поддерживает следующий функционал:
 *  - извлечение путей к файлам по синтаксису с символом '@';
 *  - построение вложений ({@see AttachmentDto}) по найденным путям с учётом
 *    настроек {@see ConfigurationApp};
 *  - извлечение команд с префиксом "@@" и разбор их в объекты {@see CmdDto}.
 *
 * Для @-ссылок:
 *  - строка начинается с символа '@' и далее идёт путь к файлу;
 *  - либо перед символом '@' стоит пробел, затем путь к файлу до первого пробела.
 *
 * По найденным путям файлы ищутся только в директориях, заданных в {@see DirPriority},
 * полученном из {@see ConfigurationApp}. Для каждого существующего файла создаётся
 * подходящий {@see AttachmentDto}, если в конфигурации приложения включена опция
 * context_files.enabled и не превышен лимит context_files.max_total_size.
 */
final class FileContextHelper
{
    /**
     * Извлекает пути к файлам из текста по синтаксису с символом '@'.
     *
     * Срабатывает, если:
     *  - '@' стоит в начале строки; или
     *  - перед '@' стоит пробел или табуляция.
     *
     * После '@' путь берётся до первого пробела/табуляции или конца строки.
     *
     * @param string $body Текст, в котором ищутся @-ссылки на файлы.
     *
     * @return list<string> Список путей без символа '@' (порядок следования в тексте).
     */
    public static function extractFilePathsFromBody(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $paths = [];

        if (preg_match_all('/(^|[ \t])@(?P<path>\S+)/m', $body, $matches) === 1 || !empty($matches['path'])) {
            foreach ($matches['path'] as $rawPath) {
                $trimmed = trim($rawPath);
                if ($trimmed !== '') {
                    $paths[] = $trimmed;
                }
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * Извлекает командные конструкции с префиксом "@@" из произвольного текста.
     *
     * Поиск команд выполняется последовательно слева направо и не перекрывает
     * уже разобранные участки текста. Для каждой найденной подстроки:
     *  - определяется позиция "@@";
     *  - читается имя команды (идентификатор [a-zA-Z0-9_]+);
     *  - при наличии "(", подбирается соответствующая закрывающая скобка с
     *    учётом строк и вложенности;
     *  - сформированный фрагмент передаётся в {@see CmdDto::fromString()}.
     *
     * В результирующий список включаются только команды с непустым именем.
     * Сломанный синтаксис (например, "@@" без имени или незавершённые скобки)
     * приводит к пропуску конкретного фрагмента без выброса исключений.
     *
     * @param string $body Текст, в котором ищутся команды.
     *
     * @return list<CmdDto> Список DTO-команд в порядке появления в тексте.
     */
    public static function extractCmdFromBody(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $length = strlen($body);
        $result = [];
        $offset = 0;

        while ($offset < $length) {
            $pos = strpos($body, '@@', $offset);
            if ($pos === false) {
                break;
            }

            $start = $pos;
            $i     = $pos + 2;

            // Читаем имя команды.
            $name = '';
            while ($i < $length) {
                $ch = $body[$i];
                if (
                    ($ch >= 'a' && $ch <= 'z')
                    || ($ch >= 'A' && $ch <= 'Z')
                    || ($ch >= '0' && $ch <= '9')
                    || $ch === '_'
                ) {
                    $name .= $ch;
                    $i++;
                    continue;
                }
                break;
            }

            if ($name === '') {
                $offset = $pos + 2;
                continue;
            }

            // Пропускаем пробелы после имени.
            while ($i < $length && ctype_space($body[$i])) {
                $i++;
            }

            $end = $i;

            if ($i < $length && $body[$i] === '(') {
                // Подбираем закрывающую скобку.
                $depth       = 0;
                $inString    = false;
                $stringQuote = '';
                $escaped     = false;

                for ($j = $i; $j < $length; $j++) {
                    $ch = $body[$j];

                    if ($inString) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($ch === '\\') {
                            $escaped = true;
                        } elseif ($ch === $stringQuote) {
                            $inString = false;
                        }
                        continue;
                    }

                    if ($ch === '"' || $ch === "'") {
                        $inString    = true;
                        $stringQuote = $ch;
                        continue;
                    }

                    if ($ch === '(') {
                        $depth++;
                        continue;
                    }

                    if ($ch === ')') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $j + 1;
                            break;
                        }
                    }
                }
            }

            if ($end <= $start + 2 + strlen($name)) {
                $end = $start + 2 + strlen($name);
            }

            $commandText = substr($body, $start, $end - $start);
            $dto         = CmdDto::fromString($commandText);

            if ($dto->getName() !== '') {
                $result[] = $dto;
            }

            $offset = $end;
        }

        return $result;
    }
}
