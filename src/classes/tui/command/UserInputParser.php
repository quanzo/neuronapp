<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\tui\command;

use app\modules\neuron\classes\dto\tui\command\ParsedUserInputDto;

/**
 * Парсер пользовательского ввода в TUI.
 *
 * Поддерживает команды вида:
 * - `/help`
 * - `/ws ls`
 * - `/ws add \"path with spaces\"`
 *
 * Пример использования:
 *
 * ```php
 * $dto = (new UserInputParser())->parse('/help');
 * ```
 */
final class UserInputParser
{
    /**
     * Парсит «сырую» строку пользователя в DTO.
     *
     * Правила:
     * - пустая строка → не команда;
     * - строка без префикса `/` → не команда;
     * - строка вида `/` или `/   ` → команда без имени (isCommand=true, commandName=null);
     * - аргументы поддерживают кавычки: `"a b"` и `'a b'`.
     *
     * @param string $raw Исходный ввод пользователя (может быть многострочным, но команды обычно однострочные)
     * @return ParsedUserInputDto
     */
    public function parse(string $raw): ParsedUserInputDto
    {
        $trim = trim($raw);
        if ($trim === '') {
            return new ParsedUserInputDto($raw, false, null, []);
        }

        if (!str_starts_with($trim, '/')) {
            return new ParsedUserInputDto($raw, false, null, []);
        }

        $withoutSlash = trim(substr($trim, 1));
        if ($withoutSlash === '') {
            return new ParsedUserInputDto($raw, true, null, []);
        }

        $parts = $this->splitArgs($withoutSlash);
        $cmd = array_shift($parts);

        return new ParsedUserInputDto(
            raw: $raw,
            isCommand: true,
            commandName: $cmd !== null ? (string) $cmd : null,
            args: $parts,
        );
    }

    /**
     * Разбивает строку аргументов на токены, учитывая кавычки.
     *
     * Поддерживается:
     * - двойные кавычки с экранированием (`\"`), результат — `stripcslashes`;
     * - одинарные кавычки без экранирования;
     * - некавычечные токены, разделённые пробелами.
     *
     * @return list<string>
     */
    private function splitArgs(string $s): array
    {
        preg_match_all('/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\']*)\'|(\\S+)/u', $s, $m);
        $out = [];
        foreach ($m[0] as $i => $_) {
            $val = $m[1][$i] !== '' ? stripcslashes($m[1][$i]) : ($m[2][$i] !== '' ? $m[2][$i] : $m[3][$i]);
            $out[] = $val;
        }
        return $out;
    }
}
