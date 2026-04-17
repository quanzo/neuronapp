<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui\history;

use app\modules\neuron\enums\tui\TuiEntryStatusEnum;
use app\modules\neuron\enums\tui\TuiHistoryEntryKindEnum;
use app\modules\neuron\interfaces\tui\view\TuiBlockInterface;

/**
 * DTO записи истории TUI (Variant C).
 *
 * Запись представляет собой атом истории: ввод пользователя, вывод системы или событие.
 * Внутри хранит список блоков (виджетов). На этапе миграции допускается `plainText`
 * как временная «плоская» форма вывода.
 *
 * Пример использования:
 *
 * ```php
 * $entry = TuiHistoryEntryDto::output('Hello');
 * ```
 */
final class TuiHistoryEntryDto
{
    private string $id;
    private \DateTimeImmutable $createdAt;
    private TuiHistoryEntryKindEnum $kind;
    private ?string $title = null;
    private bool $collapsed = false;

    /** @var list<TuiBlockInterface> */
    private array $blocks = [];

    private ?string $plainText = null;

    private TuiEntryMetaDto $meta;

    private function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
        $this->meta = new TuiEntryMetaDto();
    }

    public static function userInput(string $text): self
    {
        $e = new self();
        $e->kind = TuiHistoryEntryKindEnum::UserInput;
        $e->plainText = $text;
        $e->meta->setStatus(TuiEntryStatusEnum::Info);
        return $e;
    }

    public static function output(string $text, TuiEntryStatusEnum $status = TuiEntryStatusEnum::Ok): self
    {
        $e = new self();
        $e->kind = TuiHistoryEntryKindEnum::Output;
        $e->plainText = $text;
        $e->meta->setStatus($status);
        return $e;
    }

    public static function event(string $text, TuiEntryStatusEnum $status = TuiEntryStatusEnum::Info): self
    {
        $e = new self();
        $e->kind = TuiHistoryEntryKindEnum::Event;
        $e->plainText = $text;
        $e->meta->setStatus($status);
        return $e;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getKind(): TuiHistoryEntryKindEnum
    {
        return $this->kind;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function isCollapsed(): bool
    {
        return $this->collapsed;
    }

    public function setCollapsed(bool $collapsed): self
    {
        $this->collapsed = $collapsed;
        return $this;
    }

    /**
     * @return list<TuiBlockInterface>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * @param list<TuiBlockInterface> $blocks
     */
    public function setBlocks(array $blocks): self
    {
        $this->blocks = array_values($blocks);
        return $this;
    }

    public function getMeta(): TuiEntryMetaDto
    {
        return $this->meta;
    }

    public function setMeta(TuiEntryMetaDto $meta): self
    {
        $this->meta = $meta;
        return $this;
    }

    /**
     * Временный «плоский» текст до подключения форматтера блоков.
     */
    public function getPlainText(): ?string
    {
        return $this->plainText;
    }

    public function setPlainText(?string $plainText): self
    {
        $this->plainText = $plainText;
        return $this;
    }
}
