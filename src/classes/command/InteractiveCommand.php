<?php

namespace app\modules\neron;

use app\modules\neron\classes\status\ModeStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use app\modules\neron\classes\status\StatusBar;
use app\modules\neron\classes\status\CursorPositionStatus;
use app\modules\neron\classes\status\HistoryCountStatus;

/**
 * Интерактивная TUI-команда.
 *
 * Особенности:
 * - Адаптивная геометрия: при изменении размеров терминала все элементы пересчитываются.
 * - Поддержка Unicode (включая русские буквы).
 * - Частичная перерисовка для минимизации мерцания.
 * - Переключение фокуса между областью вывода и полем ввода (Tab).
 * - Прокрутка истории сообщений стрелками и PageUp/PageDown.
 * - Многострочное поле ввода (3 строки) с редактированием.
 * - Строка состояния собирается из объектов-статусов.
 */
class InteractiveCommand extends Command
{
    protected static $defaultName = 'interactive';

    /** @var Terminal Объект для получения размеров терминала */
    private Terminal $terminal;

    /** @var array История отправленных сообщений */
    private array $history = [];

    /**
     * Добавляет сообщение в историю.
     *
     * @param string $message
     */
    protected function addMessage(string $message): void
    {
        $this->history[] = $message;
    }

    /** @var array Три строки поля ввода */
    private array $inputLines = ['', '', ''];

    /** @var int Текущая строка в поле ввода (0, 1, 2) */
    private int $cursorRow = 0;

    /** @var int Текущая позиция в строке (символов от начала) */
    private int $cursorCol = 0;

    /** @var int Смещение прокрутки области вывода (в строках) */
    private int $outputScroll = 0;

    /** @var string Фокус ввода: 'input' или 'output' */
    private string $focus = 'input';

    /** @var bool Флаг работы цикла */
    private bool $running = true;

    /** @var StatusBar Строка состояния */
    private StatusBar $statusBar;

    /** @var array Предыдущее состояние строк ввода (для частичной перерисовки) */
    private array $prevInputLines = ['', '', ''];

    /** @var string Предыдущее состояние строки статуса (для частичной перерисовки) */
    private string $prevStatusLine = '';

    /** @var int Предыдущая ширина терминала (для обнаружения изменения размера) */
    private int $prevWidth;

    /** @var int Предыдущая высота терминала (для обнаружения изменения размера) */
    private int $prevHeight;

    /** @var bool Флаг необходимости полной перерисовки */
    private bool $fullRedraw = true;

    /** ANSI-цвета для рамок */
    private const COLOR_GREEN = "\033[92m";
    private const COLOR_GRAY  = "\033[90m";
    private const COLOR_RESET = "\033[0m";

    /** Минимальная высота терминала, необходимая для работы (9 строк) */
    private const MIN_HEIGHT = 9;

    protected function configure(): void
    {
        $this->setDescription('Запускает интерактивный TUI-интерфейс (адаптивный)');
    }

    /**
     * Точка входа в команду.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Устанавливаем локаль для корректной работы mbstring с UTF-8
        setlocale(LC_ALL, 'en_US.UTF-8');

        $this->terminal = new Terminal();
        $this->statusBar = new StatusBar();
        $this->prevWidth = $this->terminal->getWidth();
        $this->prevHeight = $this->terminal->getHeight();

        // Проверяем, достаточно ли высоты для отображения всех элементов
        if (!$this->checkMinSize()) {
            $output->writeln("<error>Терминал слишком маленький. Нужна высота не менее " . self::MIN_HEIGHT . " строк.</error>");
            return Command::FAILURE;
        }

        $this->configureTerminal();
        $this->enableAltBuffer();
        $this->runLoop();
        $this->disableAltBuffer();
        $this->restoreTerminal();

        return Command::SUCCESS;
    }

    /**
     * Проверяет, соответствует ли текущая высота терминала минимальной.
     */
    private function checkMinSize(): bool
    {
        return $this->terminal->getHeight() >= self::MIN_HEIGHT;
    }

    /**
     * Включает альтернативный буфер терминала (устраняет мерцание).
     */
    private function enableAltBuffer(): void
    {
        echo "\033[?1049h";
    }

    /**
     * Выключает альтернативный буфер, возвращая основной.
     */
    private function disableAltBuffer(): void
    {
        echo "\033[?1049l";
    }

    /**
     * Настраивает терминал для неканонического режима и скрывает курсор.
     */
    private function configureTerminal(): void
    {
        system('stty -icanon -echo');
        echo "\033[?25l"; // скрыть курсор
    }

    /**
     * Восстанавливает терминал в исходное состояние.
     */
    private function restoreTerminal(): void
    {
        system('stty icanon echo');
        echo "\033[?25h"; // показать курсор
    }

    /**
     * Главный цикл обработки ввода и отрисовки.
     */
    private function runLoop(): void
    {
        $stdin = fopen('php://stdin', 'r');
        stream_set_blocking($stdin, false);

        $read = [$stdin];
        $write = [];
        $except = [];

        while ($this->running) {
            // Проверяем, не изменился ли размер терминала
            $this->checkResize();

            // Выполняем перерисовку, если необходимо
            if ($this->fullRedraw) {
                $this->renderFull();
                $this->fullRedraw = false;
            } else {
                $this->renderPartial();
            }

            // Ожидаем ввод с таймаутом 200 мс (позволяет реагировать на изменение размера)
            $r = $read;
            $w = $write;
            $e = $except;
            $ready = stream_select($r, $w, $e, 0, 200000);

            if ($ready === false) {
                usleep(10000); // при ошибке небольшая пауза
                continue;
            }

            if ($ready > 0) {
                // Есть данные в STDIN
                $char = $this->readChar($stdin);
                if ($char === null) {
                    continue;
                }

                // Обработка escape-последовательностей (стрелки, PageUp, PageDown)
                if ($char === "\033") {
                    $this->handleEscapeSequence($stdin);
                } else {
                    // Обычные символы, Tab, Enter, Backspace, Ctrl+C
                    $this->handleOrdinaryChar($char);
                }
            }
        }

        fclose($stdin);
    }

    /**
     * Читает один UTF-8 символ из потока.
     *
     * @param resource $stream
     * @return string|null
     */
    private function readChar($stream): ?string
    {
        $byte = fgetc($stream);
        if ($byte === false) {
            return null;
        }
        $ord = ord($byte);

        // Определяем количество байт в UTF-8 символе по первому байту
        if ($ord < 0x80) {
            // 1 байт (ASCII)
            return $byte;
        } elseif ($ord < 0xE0) {
            // 2 байта
            $second = fgetc($stream);
            return $second === false ? null : $byte . $second;
        } elseif ($ord < 0xF0) {
            // 3 байта
            $second = fgetc($stream);
            $third = fgetc($stream);
            return ($second === false || $third === false) ? null : $byte . $second . $third;
        } else {
            // 4 байта
            $second = fgetc($stream);
            $third = fgetc($stream);
            $fourth = fgetc($stream);
            return ($second === false || $third === false || $fourth === false) ? null : $byte . $second . $third . $fourth;
        }
    }

    /**
     * Проверяет изменение размера терминала и при необходимости ставит флаг полной перерисовки.
     */
    private function checkResize(): void
    {
        // Создаём новый объект Terminal, чтобы получить актуальные размеры
        $this->terminal = new Terminal();
        $w = $this->terminal->getWidth();
        $h = $this->terminal->getHeight();
        if ($w !== $this->prevWidth || $h !== $this->prevHeight) {
            $this->prevWidth = $w;
            $this->prevHeight = $h;
            $this->fullRedraw = true;
        }
    }

    /**
     * Обрабатывает escape-последовательность (начинается с ESC).
     *
     * @param resource $stdin
     */
    private function handleEscapeSequence($stdin): void
    {
        $next = $this->readChar($stdin);
        if ($next === '[') {
            $third = $this->readChar($stdin);
            // Проверяем на PageUp/PageDown (последовательности [5~ и [6~)
            if ($third === '5' || $third === '6') {
                $fourth = $this->readChar($stdin);
                if ($fourth === '~') {
                    $this->processEscape('[' . $third . '~');
                }
            } else {
                $this->processEscape('[' . $third);
            }
        }
    }

    /**
     * Анализирует конкретную escape-последовательность и изменяет состояние.
     *
     * @param string $seq Последовательность без начального ESC (например, '[A').
     */
    private function processEscape(string $seq): void
    {
        $oldFocus = $this->focus;
        $oldScroll = $this->outputScroll;

        switch ($seq) {
            case '[A': // Стрелка вверх
                if ($this->focus === 'input') {
                    $this->cursorRow = max(0, $this->cursorRow - 1);
                } else {
                    $this->outputScroll = max(0, $this->outputScroll - 1);
                }
                break;
            case '[B': // Стрелка вниз
                if ($this->focus === 'input') {
                    $this->cursorRow = min(2, $this->cursorRow + 1);
                } else {
                    $this->outputScroll = min($this->getMaxOutputScroll(), $this->outputScroll + 1);
                }
                break;
            case '[C': // Стрелка вправо
                if ($this->focus === 'input') {
                    $len = mb_strlen($this->inputLines[$this->cursorRow]);
                    if ($this->cursorCol < $len) {
                        $this->cursorCol++;
                    }
                }
                break;
            case '[D': // Стрелка влево
                if ($this->focus === 'input') {
                    $this->cursorCol = max(0, $this->cursorCol - 1);
                }
                break;
            case '[5~': // PageUp
                if ($this->focus === 'output') {
                    $pageSize = $this->getOutputPageSize();
                    $this->outputScroll = max(0, $this->outputScroll - $pageSize);
                }
                break;
            case '[6~': // PageDown
                if ($this->focus === 'output') {
                    $pageSize = $this->getOutputPageSize();
                    $this->outputScroll = min($this->getMaxOutputScroll(), $this->outputScroll + $pageSize);
                }
                break;
        }

        // Если изменился фокус или прокрутка, нужна полная перерисовка
        if ($oldFocus !== $this->focus) {
            $this->fullRedraw = true;
        }
        if ($oldScroll !== $this->outputScroll) {
            $this->fullRedraw = true;
        }
    }

    /**
     * Обрабатывает обычные символы (не escape-последовательности).
     *
     * @param string $char
     */
    private function handleOrdinaryChar(string $char): void
    {
        $oldFocus = $this->focus;

        if ($char === "\t") {
            // Переключение фокуса по Tab
            $this->focus = $this->focus === 'input' ? 'output' : 'input';
        } elseif ($char === "\n" || $char === "\r") {
            // Enter – отправка сообщения (только в режиме ввода)
            if ($this->focus === 'input') {
                $message = implode("\n", $this->inputLines);
                $this->addMessage($message);
                $this->inputLines = ['', '', ''];
                $this->cursorRow = 0;
                $this->cursorCol = 0;
                // Обновляем объект терминала перед вычислением максимальной прокрутки
                $this->terminal = new Terminal();
                $this->outputScroll = $this->getMaxOutputScroll();
                $this->fullRedraw = true;
            }
        } elseif ($char === "\177" || $char === "\x7f") {
            // Backspace
            if ($this->focus === 'input') {
                $line = &$this->inputLines[$this->cursorRow];
                if ($this->cursorCol > 0) {
                    $line = mb_substr($line, 0, $this->cursorCol - 1)
                        . mb_substr($line, $this->cursorCol);
                    $this->cursorCol--;
                }
            }
        } elseif (mb_strlen($char) > 0) {
            // Любой печатный символ (включая Unicode)
            if ($this->focus === 'input') {
                $line = &$this->inputLines[$this->cursorRow];
                $line = mb_substr($line, 0, $this->cursorCol)
                    . $char
                    . mb_substr($line, $this->cursorCol);
                $this->cursorCol++;
            }
        } elseif ($char === "\x03") {
            // Ctrl+C – выход
            $this->running = false;
        }

        // Если изменился фокус, нужна полная перерисовка
        if ($oldFocus !== $this->focus) {
            $this->fullRedraw = true;
        }
    }

    // ==================== МЕТОДЫ ГЕОМЕТРИИ ====================
    // Все эти методы используют актуальную высоту терминала ($this->terminal->getHeight())
    // и позволяют адаптировать интерфейс при изменении размера окна.

    /**
     * Номер строки, с которой начинается содержимое области вывода (первая строка после верхней рамки).
     * @return int
     */
    private function getOutputContentStart(): int
    {
        return 2; // всегда 2, потому что 1-я строка – верхняя граница
    }

    /**
     * Номер строки, на которой заканчивается содержимое области вывода (последняя строка перед нижней рамкой).
     * @return int
     */
    private function getOutputContentEnd(): int
    {
        // Высота терминала минус 7: (статус 1 + поле ввода 3 + верхняя граница поля 1 + нижняя граница поля 1 + нижняя граница вывода 1)
        return $this->terminal->getHeight() - 7;
    }

    /**
     * Количество строк, доступных для отображения содержимого области вывода.
     * @return int
     */
    private function getOutputContentLines(): int
    {
        return max(0, $this->getOutputContentEnd() - $this->getOutputContentStart() + 1);
    }

    /**
     * Номер строки, на которой располагается нижняя граница области вывода.
     * @return int
     */
    private function getOutputBottomBorder(): int
    {
        return $this->terminal->getHeight() - 6;
    }

    /**
     * Номер строки, на которой располагается верхняя граница поля ввода.
     * @return int
     */
    private function getInputTopBorder(): int
    {
        return $this->terminal->getHeight() - 5;
    }

    /**
     * Номер строки, с которой начинается содержимое поля ввода (первая строка после верхней рамки).
     * @return int
     */
    private function getInputContentStart(): int
    {
        return $this->terminal->getHeight() - 4;
    }

    /**
     * Номер строки, на которой заканчивается содержимое поля ввода (последняя строка перед нижней рамкой).
     * @return int
     */
    private function getInputContentEnd(): int
    {
        return $this->terminal->getHeight() - 2;
    }

    /**
     * Номер строки, на которой располагается нижняя граница поля ввода.
     * @return int
     */
    private function getInputBottomBorder(): int
    {
        return $this->terminal->getHeight() - 1;
    }

    /**
     * Номер строки, на которой располагается строка состояния.
     * @return int
     */
    private function getStatusLine(): int
    {
        return $this->terminal->getHeight();
    }

    // ==================== МЕТОДЫ ОТРИСОВКИ ====================

    /**
     * Полная перерисовка всего экрана.
     * Используется при старте, после изменения размера или после серьёзных изменений (например, отправка сообщения).
     */
    private function renderFull(): void
    {
        // Очищаем альтернативный буфер и перемещаем курсор в начало
        echo "\033[2J\033[H";

        $width = $this->terminal->getWidth();

        $this->drawOutputAreaFull($width);
        $this->drawInputAreaFull($width);

        // Обновляем и рисуем строку состояния
        $this->updateStatusBar();
        $statusLine = $this->statusBar->render($width) . " | Tab переключить | Enter отправить | Ctrl+C выход";
        $this->drawStatusLine($statusLine, $width);

        // Сохраняем текущее состояние для последующей частичной перерисовки
        $this->prevInputLines = $this->inputLines;
        $this->prevStatusLine = $statusLine;

        // Устанавливаем курсор в нужную позицию
        $this->positionCursor();
    }

    /**
     * Частичная перерисовка – обновляет только те элементы, которые изменились.
     * Это уменьшает мерцание.
     */
    private function renderPartial(): void
    {
        $width = $this->terminal->getWidth();

        // Обновляем строку состояния, если она изменилась
        $this->updateStatusBar();
        $statusLine = $this->statusBar->render($width) . " | Tab переключить | Enter отправить | Ctrl+C выход";
        if ($statusLine !== $this->prevStatusLine) {
            $this->drawStatusLine($statusLine, $width);
            $this->prevStatusLine = $statusLine;
        }

        // Обновляем строки поля ввода, которые изменились
        for ($row = 0; $row < 3; $row++) {
            if ($this->inputLines[$row] !== $this->prevInputLines[$row]) {
                $absY = $this->getInputContentStart() + $row;
                $this->drawInputLine($absY, $this->inputLines[$row], $width);
                $this->prevInputLines[$row] = $this->inputLines[$row];
            }
        }

        // Перемещаем курсор
        $this->positionCursor();
    }

    /**
     * Рисует всю область вывода (рамку и содержимое).
     *
     * @param int $width Текущая ширина терминала.
     */
    private function drawOutputAreaFull(int $width): void
    {
        $color = $this->focus === 'output' ? self::COLOR_GREEN : self::COLOR_GRAY;
        $reset = self::COLOR_RESET;

        // Символы для рамок (можно заменить на ASCII при необходимости)
        $hline = '─';
        $vline = '│';
        $tl = '┌';
        $tr = '┐';
        $bl = '└';
        $br = '┘';
        $innerWidth = $width - 2;

        // Верхняя граница
        echo $color . $tl . $this->repeatChar($hline, $innerWidth) . $tr . $reset . "\n";

        // Получаем строки для отображения в области вывода
        $displayLines = $this->getDisplayLines($innerWidth);
        $totalLines = count($displayLines);
        $visibleLines = $this->getOutputContentLines();

        // Корректируем прокрутку, чтобы она не выходила за пределы
        $maxScroll = max(0, $totalLines - $visibleLines);
        $this->outputScroll = min($this->outputScroll, $maxScroll);
        $startIdx = $this->outputScroll;
        $endIdx = min($startIdx + $visibleLines, $totalLines);

        // Рисуем строки содержимого
        for ($i = $startIdx; $i < $endIdx; $i++) {
            $line = $displayLines[$i];
            $display = mb_strimwidth($line, 0, $innerWidth, '', 'UTF-8');
            echo $color . $vline . $reset . $this->padString($display, $innerWidth) . $color . $vline . $reset . "\n";
        }

        // Если строк меньше, чем видимая область, заполняем пустыми строками
        for ($i = $endIdx - $startIdx; $i < $visibleLines; $i++) {
            echo $color . $vline . $reset . str_repeat(' ', $innerWidth) . $color . $vline . $reset . "\n";
        }

        // Нижняя граница области вывода
        echo $color . $bl . $this->repeatChar($hline, $innerWidth) . $br . $reset . "\n";
    }

    /**
     * Рисует всю область ввода (рамку и три строки).
     *
     * @param int $width Текущая ширина терминала.
     */
    private function drawInputAreaFull(int $width): void
    {
        $color = $this->focus === 'input' ? self::COLOR_GREEN : self::COLOR_GRAY;
        $reset = self::COLOR_RESET;

        $hline = '─';
        $vline = '│';
        $tl = '┌';
        $tr = '┐';
        $bl = '└';
        $br = '┘';
        $innerWidth = $width - 2;

        // Верхняя граница поля ввода
        echo $color . $tl . $this->repeatChar($hline, $innerWidth) . $tr . $reset . "\n";

        // Три строки ввода
        for ($row = 0; $row < 3; $row++) {
            $display = mb_strimwidth($this->inputLines[$row], 0, $innerWidth, '', 'UTF-8');
            echo $color . $vline . $reset . $this->padString($display, $innerWidth) . $color . $vline . $reset . "\n";
        }

        // Нижняя граница поля ввода
        echo $color . $bl . $this->repeatChar($hline, $innerWidth) . $br . $reset . "\n";
    }

    /**
     * Рисует строку состояния.
     *
     * @param string $content Содержимое строки (может содержать ANSI-коды цвета).
     * @param int    $width   Ширина терминала.
     */
    private function drawStatusLine(string $content, int $width): void
    {
        $y = $this->getStatusLine();
        // Удаляем ANSI-коды для подсчёта видимой длины
        $visibleLength = mb_strwidth(preg_replace('/\033\[[0-9;]*m/', '', $content));
        if ($visibleLength > $width) {
            // Если слишком длинная – обрезаем
            $content = mb_strimwidth($content, 0, $width, '', 'UTF-8');
        } else {
            // Дополняем пробелами до конца строки, чтобы стереть предыдущий вывод
            $content .= str_repeat(' ', $width - $visibleLength);
        }
        echo "\033[{$y};1H" . $content;
    }

    /**
     * Рисует одну строку внутри области ввода (без рамок, только содержимое).
     * Используется для частичного обновления.
     *
     * @param int    $y       Абсолютная строка терминала, куда выводить.
     * @param string $content Содержимое строки.
     * @param int    $width   Ширина терминала.
     */
    private function drawInputLine(int $y, string $content, int $width): void
    {
        $color = $this->focus === 'input' ? self::COLOR_GREEN : self::COLOR_GRAY;
        $reset = self::COLOR_RESET;
        $innerWidth = $width - 2;
        $display = mb_strimwidth($content, 0, $innerWidth, '', 'UTF-8');
        $line = $color . "│" . $reset . $this->padString($display, $innerWidth) . $color . "│" . $reset;
        echo "\033[{$y};1H" . $line;
    }

    /**
     * Устанавливает курсор в нужную позицию в зависимости от фокуса.
     */
    private function positionCursor(): void
    {
        if ($this->focus === 'input') {
            $row = $this->getInputContentStart() + $this->cursorRow;
            $col = 1 + $this->cursorCol; // +1 из-за левой рамки '│'
            echo "\033[{$row};{$col}H";
        } else {
            // Если фокус на выводе, убираем курсор в правый нижний угол, чтобы он не мешал
            echo "\033[{$this->getStatusLine()};{$this->terminal->getWidth()}H";
        }
    }

    /**
     * Обновляет строку состояния, собирая информацию из текущих статусов.
     */
    private function updateStatusBar(): void
    {
        $mode = $this->focus === 'input' ? 'ВВОД' : 'ПРОСМОТР';
        $this->statusBar->setStatuses([
            new ModeStatus($mode),
            new CursorPositionStatus($this->cursorRow, $this->cursorCol),
            new HistoryCountStatus(count($this->history)),
        ]);
    }

    // ==================== ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ====================

    /**
     * Повторяет символ заданное количество раз (для построения линий рамок).
     *
     * @param string $char
     * @param int    $count
     * @return string
     */
    private function repeatChar(string $char, int $count): string
    {
        return str_repeat($char, $count);
    }

    /**
     * Дополняет строку пробелами справа до нужной ширины (в колонках терминала).
     * Учитывает ширину многобайтовых символов.
     *
     * @param string $str
     * @param int    $width
     * @return string
     */
    private function padString(string $str, int $width): string
    {
        $current = mb_strwidth($str);
        if ($current >= $width) {
            return $str;
        }
        return $str . str_repeat(' ', $width - $current);
    }

    /**
     * Разбивает строку на части, каждая не шире заданной ширины (в колонках).
     * Используется для переноса длинных строк в истории.
     *
     * @param string $line
     * @param int    $maxWidth
     * @return array
     */
    private function splitByWidth(string $line, int $maxWidth): array
    {
        $result = [];
        while (mb_strwidth($line) > $maxWidth) {
            $pos = 0;
            $width = 0;
            $len = mb_strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $char = mb_substr($line, $i, 1);
                $charWidth = mb_strwidth($char);
                if ($width + $charWidth > $maxWidth) {
                    break;
                }
                $width += $charWidth;
                $pos++;
            }
            if ($pos === 0) {
                // Если даже один символ не помещается – берём его принудительно
                $pos = 1;
            }
            $result[] = mb_substr($line, 0, $pos);
            $line = mb_substr($line, $pos);
        }
        if ($line !== '') {
            $result[] = $line;
        }
        return $result;
    }

    /**
     * Преобразует историю сообщений в массив строк для отображения в области вывода.
     * Учитывает перенос длинных строк и добавляет пустые строки-разделители между сообщениями.
     *
     * @param int $innerWidth Ширина внутренней области рамки (ширина терминала минус 2).
     * @return array
     */
    private function getDisplayLines(int $innerWidth): array
    {
        $lines = [];
        foreach ($this->history as $message) {
            $messageLines = explode("\n", $message);
            foreach ($messageLines as $line) {
                foreach ($this->splitByWidth($line, $innerWidth) as $chunk) {
                    $lines[] = $chunk;
                }
            }
            $lines[] = ''; // разделитель между сообщениями (пустая строка)
        }
        // Удаляем последний пустой разделитель, если он есть
        if (!empty($lines) && end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    /**
     * Возвращает максимально возможное смещение прокрутки для текущей истории и размера области вывода.
     *
     * @return int
     */
    private function getMaxOutputScroll(): int
    {
        $innerWidth = $this->terminal->getWidth() - 2;
        $displayLines = $this->getDisplayLines($innerWidth);
        $visibleLines = $this->getOutputContentLines();
        return max(0, count($displayLines) - $visibleLines);
    }

    /**
     * Возвращает размер страницы для прокрутки PageUp/PageDown.
     *
     * @return int
     */
    private function getOutputPageSize(): int
    {
        return max(1, $this->getOutputContentLines() - 1);
    }
}