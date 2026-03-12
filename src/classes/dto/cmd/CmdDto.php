<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\cmd;

/**
 * Базовый DTO, описывающий абстрактную команду в тексте в синтаксисе с префиксом "@@".
 *
 * Команда представляется фрагментом текста вида:
 *  - "@@name" — команда без аргументов;
 *  - "@@name(arg1, arg2, ...)" — команда с позиционными аргументами.
 *
 * Класс инкапсулирует:
 *  - имя команды (идентификатор после "@@");
 *  - список позиционных параметров в порядке следования в строке;
 *  - логику разбора строки команды ({@see CmdDto::fromString()});
 *  - логику восстановления канонической сигнатуры ({@see CmdDto::toSignature()});
 *  - логику поиска и замены сигнатуры в тексте ({@see CmdDto::replaceSignatureInText()}).
 *
 * Конкретные команды описываются наследниками (например, {@see FuncCmdDto}). Определение
 * конкретного класса команды выполняется по имени: "func" → "FuncCmdDto" в том же
 * пространстве имён, если такой класс существует и является наследником {@see CmdDto}.
 */
class CmdDto
{
    /**
     * @param string $name   Имя команды (идентификатор после "@@", учитывается регистр).
     * @param array  $params Позиционные параметры команды в порядке следования в строке.
     */
    public function __construct(
        protected string $name,
        protected array $params = [],
    ) {
    }

    /**
     * Возвращает имя команды.
     *
     * @return string Имя команды (идентификатор после "@@").
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Возвращает массив позиционных параметров команды.
     *
     * @return list<mixed> Список параметров в порядке следования.
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Возвращает строковое представление команды в синтаксисе "@@name(...)".
     *
     * Строка формируется на основе текущего имени команды и списка позиционных
     * параметров с учётом регистра имени команды. Для параметров используется
     * единый канонический формат:
     *  - строки — в двойных кавычках, с экранированием специальных символов;
     *  - числа — в виде литералов int/float;
     *  - булевы значения — "true"/"false";
     *  - null — "null".
     *
     * @return string Строковое представление команды.
     */
    public function toSignature(): string
    {
        $parts = [];

        foreach ($this->params as $value) {
            $parts[] = $this->formatScalar($value);
        }

        if ($parts === []) {
            return '@@' . $this->name;
        }

        return '@@' . $this->name . '(' . implode(', ', $parts) . ')';
    }

    /**
     * Заменяет в тексте первое вхождение сигнатуры текущей команды на указанную строку.
     *
     * Регистр имени команды учитывается: подстановка выполняется только для
     * совпадающего по регистру имени команды. При этом форматирование пробелов
     * в аргументах и вокруг скобок не обязано полностью совпадать с канонической
     * сигнатурой ({@see CmdDto::toSignature()}): допустимы варианты вида
     * "@@func(\"text\",1)", "@@func (\"text\", 1)" и т.п.
     *
     * @param string $body        Текст, в котором требуется выполнить замену.
     * @param string $replacement Строка, на которую будет заменена сигнатура команды.
     *
     * @return string Текст с выполненной (или без выполнения) заменой.
     */
    public function replaceSignatureInText(string $body, string $replacement): string
    {
        if ($this->name === '') {
            return $body;
        }

        // Строим шаблон сигнатуры, допускающий вариации пробелов вокруг запятых и скобок.
        $namePattern = preg_quote($this->name, '/');

        if ($this->params === []) {
            // @@name или @@name() с любыми пробелами вокруг скобок.
            $pattern = '/@@' . $namePattern . '(?:\s*\(\s*\))?/';
            return preg_replace($pattern, $replacement, $body, 1) ?? $body;
        }

        $parts = [];
        foreach ($this->params as $value) {
            $scalar = $this->formatScalar($value);
            $parts[] = '\s*' . preg_quote($scalar, '/');
        }

        $argsPattern = implode('\s*,', $parts);
        $pattern     = '/@@' . $namePattern . '\s*\(' . $argsPattern . '\s*\)/';

        return preg_replace($pattern, $replacement, $body, 1) ?? $body;
    }

    /**
     * Удаляет из текста все вхождения корректных сигнатур команд с префиксом "@@".
     *
     * Метод последовательно находит фрагменты, начинающиеся с "@@", пытается
     * распарсить их в объекты {@see CmdDto} и для каждой успешно распознанной
     * команды вырезает одно её вхождение из текста через
     * {@see CmdDto::replaceSignatureInText()}.
     *
     * Поиск чувствителен к регистру имени команды, но допускает различия в
     * форматировании пробелов вокруг аргументов и скобок (аналогично
     * {@see CmdDto::replaceSignatureInText()}).
     *
     * @param string $body Исходный текст, содержащий команды и произвольный контент.
     *
     * @return string Текст без сигнатур команд.
     */
    public static function replaceAllInText(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $offset = 0;

        while (true) {
            $length = strlen($body);
            if ($offset >= $length) {
                break;
            }

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
                // Подбираем закрывающую скобку для аргументов.
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
            $dto         = self::fromString($commandText);

            if ($dto->getName() === '') {
                $offset = $end;
                continue;
            }

            $before = $body;
            $body   = $dto->replaceSignatureInText($body, '');

            if ($body === $before) {
                // Защита от зацикливания в случае, если замена не сработала.
                $offset = $end;
            } else {
                // После удаления продолжаем поиск с той же позиции.
                $offset = $start;
            }
        }

        return $body;
    }

    /**
     * Создаёт DTO-команду из строки вида "@@name(arg1, arg2, ...)".
     *
     * Метод отвечает за полный цикл разбора текстового представления команды:
     *  1. Нормализует входную строку (обрезает пробелы, проверяет префикс "@@").
     *  2. Извлекает имя команды — непрерывную последовательность символов
     *     [a-zA-Z0-9_] сразу после префикса.
     *  3. Извлекает (при наличии) подстроку с аргументами в круглых скобках и
     *     разбирает её в массив скаляров с приведением типов.
     *  4. По имени команды определяет класс-наследник {@see CmdDto} и, если он
     *     существует, делегирует создание объекта через {@see CmdDto::fromParts()}.
     *  5. В противном случае возвращает экземпляр базового {@see CmdDto}.
     *
     * При синтаксических аномалиях (отсутствие "@@", пустое имя, несбалансированные
     * скобки и т.п.) метод НЕ выбрасывает исключения. В типичных случаях такие
     * строки приводят к объекту с пустым именем и пустым списком параметров.
     *
     * @param string $command Строка с командой в синтаксисе "@@...".
     *
     * @return self Экземпляр подходящего DTO-командного класса или базовый {@see CmdDto}.
     */
    public static function fromString(string $command): self
    {
        $trimmed = trim($command);

        if ($trimmed === '' || !str_starts_with($trimmed, '@@')) {
            return new self('', []);
        }

        $withoutPrefix = substr($trimmed, 2);
        $withoutPrefix = ltrim($withoutPrefix);

        if ($withoutPrefix === '') {
            return new self('', []);
        }

        $name = '';
        $length = strlen($withoutPrefix);
        $i = 0;

        // Извлекаем идентификатор имени команды.
        while ($i < $length) {
            $ch = $withoutPrefix[$i];
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

        $name = trim($name);

        if ($name === '') {
            return new self('', []);
        }

        // Пропускаем пробелы после имени.
        while ($i < $length && ctype_space($withoutPrefix[$i])) {
            $i++;
        }

        $params = [];

        if ($i < $length && $withoutPrefix[$i] === '(') {
            $params = static::parseArgs($withoutPrefix, $i);
        }

        $fqcn = static::resolveCommandClass($name);

        if ($fqcn !== null && is_a($fqcn, self::class, true)) {
            if (method_exists($fqcn, 'fromParts')) {
                /** @psalm-suppress InvalidStringClass */
                return $fqcn::fromParts($name, $params);
            }
        }

        return new self($name, $params);
    }

    /**
     * Создаёт экземпляр DTO-команды из разобранных частей.
     *
     * Потомки могут переопределить этот метод для реализации дополнительной
     * семантики: валидации набора аргументов, приведения типов к более
     * строгим, установке значений по умолчанию и т.п. Базовая реализация
     * просто создаёт новый экземпляр {@see CmdDto} с переданными значениями.
     *
     * @param string $name   Имя команды.
     * @param array  $params Позиционные параметры команды.
     *
     * @return self Экземпляр соответствующего класса DTO-команды.
     */
    protected static function fromParts(string $name, array $params): self
    {
        return new self($name, $params);
    }

    /**
     * Определяет FQCN класса команды по её имени.
     *
     * Имя команды "func" будет сопоставлено с классом "FuncCmdDto"
     * в текущем пространстве имён.
     *
     * @param string $name Имя команды.
     *
     * @return string|null Полностью квалифицированное имя класса или null, если класс не найден.
     */
    protected static function resolveCommandClass(string $name): ?string
    {
        $className = ucfirst($name) . 'CmdDto';
        $fqcn = __NAMESPACE__ . '\\' . $className;

        if (class_exists($fqcn)) {
            return $fqcn;
        }

        return null;
    }

    /**
     * Разбирает подстроку с аргументами команды, начиная с открывающей скобки.
     *
     * Поддерживаются:
     *  - строки в одинарных и двойных кавычках с экранированием с помощью "\\";
     *  - простые скаляры: числа, true, false, null.
     *
     * Метод реализует простой парсер аргументов: он отвечает только за поиск
     * соответствующей закрывающей скобки и разбиение содержимого по запятым
     * верхнего уровня, учитывая строки и экранирование.
     *
     * При синтаксической ошибке (несбалансированные скобки, незавершённая строка
     * и т.п.) возвращает массив уже распознанных аргументов, не выбрасывая
     * исключения.
     *
     * @param string $source Полная строка после имени команды.
     * @param int    $start  Индекс символа "(" в исходной строке.
     *
     * @return list<mixed> Массив распознанных аргументов.
     */
    private static function parseArgs(string $source, int $start): array
    {
        $length = strlen($source);

        if ($start < 0 || $start >= $length || $source[$start] !== '(') {
            return [];
        }

        // Находим соответствующую закрывающую скобку, учитывая строки и вложенность.
        $depth = 0;
        $inString = false;
        $stringQuote = '';
        $escaped = false;
        $end = null;

        for ($i = $start; $i < $length; $i++) {
            $ch = $source[$i];

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
                $inString = true;
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
                    $end = $i;
                    break;
                }
            }
        }

        if ($end === null || $end <= $start + 1) {
            // Пустой список аргументов или несбалансированные скобки.
            return [];
        }

        $argsPart    = substr($source, $start + 1, $end - $start - 1);
        $args        = [];
        $current     = '';
        $inString    = false;
        $stringQuote = '';
        $escaped     = false;

        $partLength = strlen($argsPart);

        for ($i = 0; $i < $partLength; $i++) {
            $ch = $argsPart[$i];

            if ($inString) {
                $current .= $ch;

                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($ch === $stringQuote) {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inString = true;
                $stringQuote = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === ',') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $args[] = static::parseScalar($trimmed);
                }
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $args[] = static::parseScalar($trimmed);
        }

        return array_values($args);
    }

    /**
     * Разбирает строковое представление скалярного значения.
     *
     * Поддерживаемые значения:
     *  - строки в одинарных или двойных кавычках (с экранированием "\\");
     *  - целые и вещественные числа;
     *  - литералы true, false, null (в нижнем регистре).
     * Всё остальное возвращается как строка без изменений.
     *
     * @param string $value Строковое представление значения.
     *
     * @return mixed Распознанное скалярное значение.
     */
    private static function parseScalar(string $value): mixed
    {
        $length = strlen($value);

        if (
            $length >= 2
            && (
                ($value[0] === '"' && $value[$length - 1] === '"')
                || ($value[0] === "'" && $value[$length - 1] === "'")
            )
        ) {
            $quote = $value[0];
            $inner = substr($value, 1, -1);

            return static::unescapeString($inner, $quote);
        }

        $lower = strtolower($value);

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if ($lower === 'null') {
            return null;
        }

        if (is_numeric($value)) {
            if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
                return (int) $value;
            }

            return (float) $value;
        }

        return $value;
    }

    /**
     * Снимает экранирование в строке аргумента.
     *
     * На текущем этапе поддерживается базовый набор:
     *  - `\\` → `\`
     *  - `\"` / `\'` → кавычка соответствующего типа.
     *
     * @param string $value Внутреннее содержимое строки без внешних кавычек.
     * @param string $quote Кавычка, использованная для строки.
     *
     * @return string Строка с применённым снятием экранирования.
     */
    private static function unescapeString(string $value, string $quote): string
    {
        $result = '';
        $length = strlen($value);
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $ch = $value[$i];

            if ($escaped) {
                if ($ch === '\\' || $ch === $quote) {
                    $result .= $ch;
                } else {
                    $result .= '\\' . $ch;
                }
                $escaped = false;
                continue;
            }

            if ($ch === '\\') {
                $escaped = true;
                continue;
            }

            $result .= $ch;
        }

        if ($escaped) {
            $result .= '\\';
        }

        return $result;
    }

    /**
     * Форматирует скалярное значение для использования в сигнатуре команды.
     *
     * Используется при построении канонического вида команды в {@see toSignature()}.
     * Выполняет обратную операцию по отношению к {@see parseScalar()}: из скаляра
     * строит строковый литерал, пригодный для безопасного включения в исходный текст.
     *
     * @param mixed $value Скалярное значение (string|int|float|bool|null).
     *
     * @return string Строковое представление значения.
     */
    private function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $escaped = '';
        $length  = strlen((string) $value);

        for ($i = 0; $i < $length; $i++) {
            $ch = $value[$i];
            if ($ch === '\\' || $ch === '"') {
                $escaped .= '\\' . $ch;
            } else {
                $escaped .= $ch;
            }
        }

        return '"' . $escaped . '"';
    }
}
