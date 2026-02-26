<?php

namespace app\modules\neron\classes\status;

use app\modules\neron\interfaces\StatusInterface;

/**
 * Статус, показывающий текущую позицию курсора в поле ввода.
 */
class CursorPositionStatus implements StatusInterface
{
    private int $row;
    private int $col;

    /**
     * @param int $row Индекс строки (0‑базовый)
     * @param int $col Индекс колонки (0‑базовый)
     */
    public function __construct(int $row, int $col)
    {
        $this->row = $row;
        $this->col = $col;
    }

    /**
     * @inheritDoc
     */
    public function getText(): string
    {
        return sprintf("Стр %d, кол %d", $this->row + 1, $this->col + 1);
    }

    /**
     * @inheritDoc
     */
    public function getColorCode(): string
    {
        return "\033[92m"; // зелёный
    }
}