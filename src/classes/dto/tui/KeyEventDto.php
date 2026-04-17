<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui;

/**
 * DTO события клавиатуры в TUI.
 *
 * Нормализует ввод (UTF-8 символы и escape-последовательности) в единый формат,
 * пригодный для чистой обработки (reducer) и тестирования.
 *
 * Пример использования:
 *
 * ```php
 * $event = KeyEventDto::text('я');
 * if ($event->isText()) { ... }
 * ```
 */
final class KeyEventDto
{
    public const TYPE_TEXT = 'text';
    public const TYPE_PASTE = 'paste';
    public const TYPE_TAB = 'tab';
    public const TYPE_ENTER = 'enter';
    public const TYPE_BACKSPACE = 'backspace';
    public const TYPE_DELETE = 'delete';
    public const TYPE_HOME = 'home';
    public const TYPE_END = 'end';
    public const TYPE_CTRL_C = 'ctrl_c';
    public const TYPE_ARROW_UP = 'arrow_up';
    public const TYPE_ARROW_DOWN = 'arrow_down';
    public const TYPE_ARROW_LEFT = 'arrow_left';
    public const TYPE_ARROW_RIGHT = 'arrow_right';
    public const TYPE_PAGE_UP = 'page_up';
    public const TYPE_PAGE_DOWN = 'page_down';
    public const TYPE_TOGGLE_MOUSE_MODE = 'toggle_mouse_mode';
    public const TYPE_MOUSE = 'mouse';

    private function __construct(
        private readonly string $type,
        private readonly ?string $text = null,
        private readonly ?string $pasteText = null,
        private readonly ?int $mouseButton = null,
        private readonly ?int $mouseX = null,
        private readonly ?int $mouseY = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self(self::TYPE_TEXT, $text);
    }

    public static function paste(string $text): self
    {
        return new self(self::TYPE_PASTE, null, $text);
    }

    public static function tab(): self
    {
        return new self(self::TYPE_TAB);
    }

    public static function enter(): self
    {
        return new self(self::TYPE_ENTER);
    }

    public static function backspace(): self
    {
        return new self(self::TYPE_BACKSPACE);
    }

    public static function delete(): self
    {
        return new self(self::TYPE_DELETE);
    }

    public static function home(): self
    {
        return new self(self::TYPE_HOME);
    }

    public static function end(): self
    {
        return new self(self::TYPE_END);
    }

    public static function ctrlC(): self
    {
        return new self(self::TYPE_CTRL_C);
    }

    public static function arrowUp(): self
    {
        return new self(self::TYPE_ARROW_UP);
    }

    public static function arrowDown(): self
    {
        return new self(self::TYPE_ARROW_DOWN);
    }

    public static function arrowLeft(): self
    {
        return new self(self::TYPE_ARROW_LEFT);
    }

    public static function arrowRight(): self
    {
        return new self(self::TYPE_ARROW_RIGHT);
    }

    public static function pageUp(): self
    {
        return new self(self::TYPE_PAGE_UP);
    }

    public static function pageDown(): self
    {
        return new self(self::TYPE_PAGE_DOWN);
    }

    public static function toggleMouseMode(): self
    {
        return new self(self::TYPE_TOGGLE_MOUSE_MODE);
    }

    public static function mouse(int $button, int $x, int $y): self
    {
        return new self(self::TYPE_MOUSE, null, null, $button, $x, $y);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isText(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getPasteText(): ?string
    {
        return $this->pasteText;
    }

    public function getMouseButton(): ?int
    {
        return $this->mouseButton;
    }

    public function getMouseX(): ?int
    {
        return $this->mouseX;
    }

    public function getMouseY(): ?int
    {
        return $this->mouseY;
    }
}
