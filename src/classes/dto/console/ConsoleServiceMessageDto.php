<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\console;

use app\modules\neuron\enums\ConsoleServiceMessageLevel;

/**
 * DTO одного сервисного сообщения консольного вывода.
 *
 * Пример:
 *
 * <code>
 * $msg = ConsoleServiceMessageDto::info('Summary обновлён');
 * </code>
 */
final class ConsoleServiceMessageDto
{
    /**
     * @param string                      $text  Текст сообщения (без Symfony-тегов).
     * @param ConsoleServiceMessageLevel $level Уровень для рендеринга md/txt.
     */
    public function __construct(
        private string $text,
        private ConsoleServiceMessageLevel $level = ConsoleServiceMessageLevel::Plain,
    ) {
    }

    /**
     * Текст сообщения.
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Уровень сообщения.
     */
    public function getLevel(): ConsoleServiceMessageLevel
    {
        return $this->level;
    }

    /**
     * Сообщение без оформления.
     */
    public static function plain(string $text): self
    {
        return new self($text, ConsoleServiceMessageLevel::Plain);
    }

    /**
     * Информационное сообщение.
     */
    public static function info(string $text): self
    {
        return new self($text, ConsoleServiceMessageLevel::Info);
    }

    /**
     * Комментарий / предупреждение без ошибки.
     */
    public static function comment(string $text): self
    {
        return new self($text, ConsoleServiceMessageLevel::Comment);
    }

    /**
     * Сериализует сообщение для JSON.
     *
     * @return array{text: string, level: string}
     */
    public function toArray(): array
    {
        return [
            'text'  => $this->text,
            'level' => $this->level->value,
        ];
    }
}
