<?php

declare(strict_types=1);

namespace Tests\Tui;

use app\modules\neuron\classes\command\input\KeySequenceParser;
use app\modules\neuron\classes\command\input\Utf8CharReader;
use app\modules\neuron\classes\dto\tui\KeyEventDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see KeySequenceParser}.
 *
 * KeySequenceParser — преобразует ввод из stdin (символы UTF‑8 и ESC‑последовательности)
 * в нормализованные события {@see KeyEventDto}.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\command\input\KeySequenceParser}
 */
class KeySequenceParserTest extends TestCase
{
    /**
     * Печатный UTF‑8 символ (кириллица) превращается в событие TYPE_TEXT.
     */
    public function testReadsUtf8TextChar(): void
    {
        $stdin = $this->streamFromString('я');
        $parser = new KeySequenceParser(new Utf8CharReader());

        $event = $parser->readEvent($stdin);
        $this->assertInstanceOf(KeyEventDto::class, $event);
        $this->assertSame(KeyEventDto::TYPE_TEXT, $event->getType());
        $this->assertSame('я', $event->getText());
    }

    /**
     * Ctrl+C (\x03) распознаётся как TYPE_CTRL_C.
     */
    public function testReadsCtrlC(): void
    {
        $stdin = $this->streamFromString("\x03");
        $parser = new KeySequenceParser(new Utf8CharReader());

        $event = $parser->readEvent($stdin);
        $this->assertSame(KeyEventDto::TYPE_CTRL_C, $event?->getType());
    }

    /**
     * Tab распознаётся как TYPE_TAB.
     */
    public function testReadsTab(): void
    {
        $stdin = $this->streamFromString("\t");
        $parser = new KeySequenceParser(new Utf8CharReader());

        $event = $parser->readEvent($stdin);
        $this->assertSame(KeyEventDto::TYPE_TAB, $event?->getType());
    }

    /**
     * Enter (\n) распознаётся как TYPE_ENTER.
     */
    public function testReadsEnter(): void
    {
        $stdin = $this->streamFromString("\n");
        $parser = new KeySequenceParser(new Utf8CharReader());

        $event = $parser->readEvent($stdin);
        $this->assertSame(KeyEventDto::TYPE_ENTER, $event?->getType());
    }

    /**
     * Backspace (\x7f) распознаётся как TYPE_BACKSPACE.
     */
    public function testReadsBackspace(): void
    {
        $stdin = $this->streamFromString("\x7f");
        $parser = new KeySequenceParser(new Utf8CharReader());

        $event = $parser->readEvent($stdin);
        $this->assertSame(KeyEventDto::TYPE_BACKSPACE, $event?->getType());
    }

    /**
     * Стрелка вверх (ESC [ A) распознаётся как TYPE_ARROW_UP.
     */
    public function testReadsArrowUpEscapeSequence(): void
    {
        $stdin = $this->streamFromString("\033[A");
        $parser = new KeySequenceParser(new Utf8CharReader());

        $event = $parser->readEvent($stdin);
        $this->assertSame(KeyEventDto::TYPE_ARROW_UP, $event?->getType());
    }

    /**
     * PageDown (ESC [ 6 ~) распознаётся как TYPE_PAGE_DOWN.
     */
    public function testReadsPageDownEscapeSequence(): void
    {
        $stdin = $this->streamFromString("\033[6~");
        $parser = new KeySequenceParser(new Utf8CharReader());

        $event = $parser->readEvent($stdin);
        $this->assertSame(KeyEventDto::TYPE_PAGE_DOWN, $event?->getType());
    }

    /**
     * Неизвестная ESC-последовательность не даёт события (возвращается null).
     */
    public function testUnknownEscapeSequenceReturnsNull(): void
    {
        $stdin = $this->streamFromString("\033[Z");
        $parser = new KeySequenceParser(new Utf8CharReader());

        $event = $parser->readEvent($stdin);
        $this->assertNull($event);
    }

    /**
     * Вспомогательный метод: создаёт поток с заданным содержимым.
     *
     * @param string $data
     * @return resource
     */
    private function streamFromString(string $data)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $data);
        rewind($stream);
        return $stream;
    }
}
