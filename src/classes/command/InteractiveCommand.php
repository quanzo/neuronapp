<?php

namespace app\modules\neuron\classes\command;

use app\modules\neuron\classes\status\ModeStatus;
use app\modules\neuron\classes\command\terminal\TerminalModeManager;
use app\modules\neuron\classes\command\input\KeySequenceParser;
use app\modules\neuron\classes\command\input\Utf8CharReader;
use app\modules\neuron\classes\command\state\TuiReducer;
use app\modules\neuron\classes\command\render\TuiRenderer;
use app\modules\neuron\classes\dto\tui\KeyEventDto;
use app\modules\neuron\classes\dto\tui\LayoutDto;
use app\modules\neuron\classes\dto\tui\PostOutputContextDto;
use app\modules\neuron\classes\dto\tui\PreOutputDecisionDto;
use app\modules\neuron\classes\dto\tui\TerminalSizeDto;
use app\modules\neuron\classes\dto\tui\TuiStateDto;
use app\modules\neuron\helpers\TuiTextHelper;
use app\modules\neuron\interfaces\tui\TuiPostOutputHookInterface;
use app\modules\neuron\interfaces\tui\TuiPreOutputHookInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use app\modules\neuron\classes\status\StatusBar;

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

    /** Минимальная высота терминала, необходимая для работы (9 строк) */
    private const MIN_HEIGHT = 9;

    private TuiPreOutputHookInterface $preHook;
    private TuiPostOutputHookInterface $postHook;

    private ?PostOutputContextDto $pendingPostHookContext = null;

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

        $this->initHooks();

        // Проверяем, достаточно ли высоты для отображения всех элементов
        if (!$this->checkMinSize()) {
            $output->writeln("<error>Терминал слишком маленький. Нужна высота не менее " . self::MIN_HEIGHT . " строк.</error>");
            return Command::FAILURE;
        }

        $terminalMode = new TerminalModeManager();
        $terminalMode->enter();
        try {
            $this->runLoop();
        } finally {
            $terminalMode->leave();
        }

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
     * Главный цикл обработки ввода и отрисовки.
     */
    private function runLoop(): void
    {
        $stdin = fopen('php://stdin', 'r');
        stream_set_blocking($stdin, false);

        $parser = new KeySequenceParser(new Utf8CharReader());
        $reducer = new TuiReducer();
        $renderer = new TuiRenderer();

        $read = [$stdin];
        $write = [];
        $except = [];

        while ($this->running) {
            // Проверяем, не изменился ли размер терминала
            $this->checkResize();

            // Выполняем перерисовку, если необходимо
            if ($this->fullRedraw) {
                $this->renderFull($renderer);
                $this->fullRedraw = false;
            } else {
                $this->renderPartial($renderer);
            }

            // Post-hook должен вызываться после фактического рендера кадра.
            $this->runPendingPostHookIfAny();

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
                $event = $parser->readEvent($stdin);
                if ($event === null) {
                    continue;
                }

                $this->applyKeyEvent($event, $reducer);
            }
        }

        fclose($stdin);
    }

    /**
     * Полная перерисовка всего экрана.
     *
     * @param TuiRenderer $renderer
     * @return void
     */
    private function renderFull(TuiRenderer $renderer): void
    {
        $this->terminal = new Terminal();
        $size = new TerminalSizeDto($this->terminal->getWidth(), $this->terminal->getHeight());
        $layout = $this->buildLayoutDto();
        $state = $this->buildStateDto();
        $state = $renderer->renderFull($state, $layout, $size, $this->statusBar);
        $this->applyStateDto($state);
    }

    /**
     * Частичная перерисовка.
     *
     * @param TuiRenderer $renderer
     * @return void
     */
    private function renderPartial(TuiRenderer $renderer): void
    {
        $this->terminal = new Terminal();
        $size = new TerminalSizeDto($this->terminal->getWidth(), $this->terminal->getHeight());
        $layout = $this->buildLayoutDto();
        $state = $this->buildStateDto();
        $state = $renderer->renderPartial($state, $layout, $size, $this->statusBar);
        $this->applyStateDto($state);
    }

    /**
     * Применяет нормализованное событие клавиатуры к текущему состоянию.
     *
     * @param KeyEventDto $event
     * @param TuiReducer  $reducer
     */
    private function applyKeyEvent(KeyEventDto $event, TuiReducer $reducer): void
    {
        $state = $this->buildStateDto();
        $layout = $this->buildLayoutDto();
        $result = $reducer->applyKeyEventWithResult($state, $event, $layout);
        $this->applyStateDto($result->getState());

        $submittedInput = $result->getSubmittedInput();
        if ($submittedInput !== null) {
            $decision = $this->preHook->decide($submittedInput);
            $this->applyPreHookDecision($decision);
        }

        // Clamp прокрутку после любых изменений.
        $this->terminal = new Terminal();
        $this->outputScroll = min($this->outputScroll, $this->getMaxOutputScroll());
    }

    /**
     * Инициализирует pre/post hooks.
     *
     * @return void
     */
    private function initHooks(): void
    {
        $this->preHook = new \app\modules\neuron\classes\command\hooks\DefaultTuiPreOutputHook();
        $this->postHook = new \app\modules\neuron\classes\command\hooks\DefaultTuiPostOutputHook();
    }

    /**
     * Применяет решение pre-hook: добавляет текст в историю (или не добавляет).
     *
     * @param PreOutputDecisionDto $decision
     * @return void
     */
    private function applyPreHookDecision(PreOutputDecisionDto $decision): void
    {
        $outputText = $decision->getOutputText();
        if ($outputText === null) {
            return;
        }

        $this->history[] = $outputText;
        $this->terminal = new Terminal();
        $this->outputScroll = $this->getMaxOutputScroll();
        $this->fullRedraw = true;

        $this->pendingPostHookContext = new PostOutputContextDto(
            originalInput: $decision->getOriginalInput(),
            renderedOutput: $outputText,
        );
    }

    /**
     * Выполняет post-hook, если он запланирован после предыдущего вывода.
     *
     * @return void
     */
    private function runPendingPostHookIfAny(): void
    {
        if ($this->pendingPostHookContext === null) {
            return;
        }

        $ctx = $this->pendingPostHookContext;
        $this->pendingPostHookContext = null;

        $extra = $this->postHook->afterRender($ctx);
        if ($extra === null || $extra === '') {
            return;
        }

        $this->history[] = $extra;
        $this->terminal = new Terminal();
        $this->outputScroll = $this->getMaxOutputScroll();
        $this->fullRedraw = true;
    }

    /**
     * Собирает DTO состояния из полей команды.
     */
    private function buildStateDto(): TuiStateDto
    {
        return (new TuiStateDto())
            ->setHistory($this->history)
            ->setInputLines($this->inputLines)
            ->setCursorRow($this->cursorRow)
            ->setCursorCol($this->cursorCol)
            ->setOutputScroll($this->outputScroll)
            ->setFocus($this->focus)
            ->setRunning($this->running)
            ->setFullRedraw($this->fullRedraw)
            ->setPrevInputLines($this->prevInputLines)
            ->setPrevStatusLine($this->prevStatusLine)
            ->setPrevWidth($this->prevWidth)
            ->setPrevHeight($this->prevHeight);
    }

    /**
     * Применяет DTO состояния к полям команды.
     */
    private function applyStateDto(TuiStateDto $state): void
    {
        $this->history = $state->getHistory();
        $this->inputLines = $state->getInputLines();
        $this->cursorRow = $state->getCursorRow();
        $this->cursorCol = $state->getCursorCol();
        $this->outputScroll = $state->getOutputScroll();
        $this->focus = $state->getFocus();
        $this->running = $state->isRunning();
        $this->fullRedraw = $state->isFullRedraw();
        $this->prevInputLines = $state->getPrevInputLines();
        $this->prevStatusLine = $state->getPrevStatusLine();
        $this->prevWidth = $state->getPrevWidth();
        $this->prevHeight = $state->getPrevHeight();
    }

    /**
     * Собирает DTO геометрии из текущего размера терминала.
     */
    private function buildLayoutDto(): LayoutDto
    {
        return new LayoutDto(
            outputContentStart: $this->getOutputContentStart(),
            outputContentEnd: $this->getOutputContentEnd(),
            inputContentStart: $this->getInputContentStart(),
            inputContentEnd: $this->getInputContentEnd(),
            statusLine: $this->getStatusLine(),
        );
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

    // (Обработка клавиш и изменение состояния вынесены в TuiReducer)

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

    /**
     * Возвращает максимально возможное смещение прокрутки для текущей истории и размера области вывода.
     *
     * @return int
     */
    private function getMaxOutputScroll(): int
    {
        $innerWidth = $this->terminal->getWidth() - 2;
        $displayLines = TuiTextHelper::buildDisplayLines($this->history, $innerWidth);
        $visibleLines = $this->getOutputContentLines();
        return max(0, count($displayLines) - $visibleLines);
    }
}
